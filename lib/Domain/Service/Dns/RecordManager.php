<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Domain\Service\Dns;

use Closure;
use Exception;
use PDO;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RepositoryFactoryInterface;
use Poweradmin\Domain\Repository\TemplateRecordLinkRepositoryInterface;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\ZoneRectifierInterface;
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\Validation\RecordField;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Port\RecordWriteBackendInterface;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Poweradmin\Domain\Service\Auth\ZoneAccessPolicy;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Creates, updates and deletes records for the web UI, with validation, logging and serial updates.
 */
class RecordManager implements RecordManagerInterface
{
    private PDO $db;
    private ConfigurationInterface $config;
    private DnsFormatter $dnsFormatter;
    private DnsRecordValidationServiceInterface $validationService;
    private SOARecordManagerInterface $soaRecordManager;
    private DomainRepositoryInterface $domainRepository;
    private RecordWriteBackendInterface&BackendCapabilitiesInterface $backendProvider;
    private LoggerInterface $logger;
    private RecordChangeWriterInterface $changeLogger;
    private PermissionService $permissionService;
    private ActorInterface $actor;
    private RepositoryFactoryInterface $repositoryFactory;
    private TemplateRecordLinkRepositoryInterface $templateLinks;
    private Closure $dnssecProvider;
    private ?ZoneRectifierInterface $builtDnssecProvider = null;

    /**
     * Constructor
     *
     * @param PDO $db Database connection, for the transaction around a record write and its serial bump
     * @param ConfigurationInterface $config Configuration manager
     * @param DnsRecordValidationServiceInterface $validationService DNS record validation service
     * @param SOARecordManagerInterface $soaRecordManager SOA record manager
     * @param DomainRepositoryInterface $domainRepository Domain repository
     * @param RepositoryFactoryInterface $repositoryFactory Builds the record and comment repositories
     * @param Closure(): ZoneRectifierInterface $dnssecProvider Built on first use, so DNSSEC-disabled installs never construct one
     * @param RecordWriteBackendInterface&BackendCapabilitiesInterface $backendProvider Writes records and says whether a local transaction wraps them
     * @param PermissionService $permissionService Edit levels and zone ownership of the acting user
     * @param RecordChangeWriterInterface $changeLogger Receives the before/after record snapshots
     * @param TemplateRecordLinkRepositoryInterface $templateLinks Drops the template link of a deleted record
     * @param ActorInterface $actor The user the edit gates are about
     */
    public function __construct(
        PDO $db,
        ConfigurationInterface $config,
        DnsRecordValidationServiceInterface $validationService,
        SOARecordManagerInterface $soaRecordManager,
        DomainRepositoryInterface $domainRepository,
        RepositoryFactoryInterface $repositoryFactory,
        Closure $dnssecProvider,
        RecordWriteBackendInterface&BackendCapabilitiesInterface $backendProvider,
        PermissionService $permissionService,
        RecordChangeWriterInterface $changeLogger,
        TemplateRecordLinkRepositoryInterface $templateLinks,
        ActorInterface $actor,
        ?LoggerInterface $logger = null
    ) {
        $this->db = $db;
        $this->templateLinks = $templateLinks;
        $this->config = $config;
        $this->dnsFormatter = new DnsFormatter($config);
        $this->validationService = $validationService;
        $this->soaRecordManager = $soaRecordManager;
        $this->domainRepository = $domainRepository;
        $this->backendProvider = $backendProvider;
        $this->permissionService = $permissionService;
        $this->logger = $logger ?? new NullLogger();
        $this->changeLogger = $changeLogger;
        $this->actor = $actor;
        $this->repositoryFactory = $repositoryFactory;
        $this->dnssecProvider = $dnssecProvider;
    }

    private function captureChange(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->logger->warning('Failed to write record change log: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if the acting user owns the zone directly or via group membership
     */
    private function userIsZoneOwner(int $zoneId): bool
    {
        $userId = $this->actor->userId();
        if ($userId === null) {
            return false;
        }
        return $this->permissionService->userOwnsZone($userId, $zoneId);
    }

    /**
     * Check if the acting user has the given permission (admins always pass).
     */
    private function userHasPermission(string $permission): bool
    {
        $userId = $this->actor->userId();
        if ($userId === null) {
            return false;
        }
        return $this->permissionService->hasPermission($userId, $permission);
    }

    /**
     * The acting user's edit level: "all", "own", "own_as_client" or "none".
     */
    private function editPermissionLevel(): string
    {
        $userId = $this->actor->userId();
        if ($userId === null) {
            return 'none';
        }
        return $this->permissionService->getEditPermissionLevel($userId);
    }

    /**
     * Resolve the zone name, normalize the record name against it, and reject
     * record types a client-level editor may not add.
     *
     * Normalization happens first so the apex comparison sees the FQDN.
     *
     * @return array{0: string, 1: string} Zone name and normalized record name
     * @throws Exception When the record type is restricted for the user
     */
    private function normalizeNameAndAssertAddAllowed(int $zone_id, string $name, string $type, string $perm_edit): array
    {
        $zone = $this->domainRepository->getDomainNameById($zone_id);
        $hostnameValidator = new HostnameValidator(HostnamePolicy::fromConfig($this->config));
        $name = $hostnameValidator->normalizeRecordName($name, $zone);

        $canEditSubzoneNs = $this->userHasPermission(Permission::PERM_EDIT_NS_SUBZONE);
        if (Permission::isRecordRestrictedForClient($type, $perm_edit, $name, $zone, $canEditSubzoneNs)) {
            throw new Exception(Permission::restrictedRecordTypeMessage($type, 'add'));
        }

        return [$zone, $name];
    }

    /**
     * Add a record
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param int $zone_id Zone ID
     * @param string $name Name part of record
     * @param string $type Type of record
     * @param string $content Content of record
     * @param int $ttl Time-To-Live of record
     * @param mixed $prio Priority of record
     *
     * @return boolean true if successful; addRecordGetId() carries the reason for a refusal
     */
    public function addRecord(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio): bool
    {
        return $this->addRecordGetId($zone_id, $name, $type, $content, $ttl, $prio)->success;
    }

    /**
     * Add a record and return its ID
     *
     * This function validates and inserts a record into the database,
     * returning the new record's ID for use with per-record comments.
     *
     * @param int $zone_id Zone ID
     * @param string $name Name part of record
     * @param string $type Type of record
     * @param string $content Content of record
     * @param int $ttl Time-To-Live of record
     * @param mixed $prio Priority of record
     * @param int $disabled Whether the record is created in disabled state (0 or 1)
     * @param bool $finalizeZone Bump the serial and rectify; a batch caller does that once itself
     * @param array|null $comment RRset comment ['content' => string, 'account' => string]; the API
     *                            backend writes it in the same PATCH as the record, SQL ignores it
     *
     * @return RecordWriteResult Carries the new record id, or the reason it was refused
     */
    public function addRecordGetId(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio, int $disabled = 0, bool $finalizeZone = true, ?array $comment = null): RecordWriteResult
    {
        $perm_edit = $this->editPermissionLevel();

        $user_is_zone_owner = $this->userIsZoneOwner($zone_id);
        $zone_type = $this->domainRepository->getDomainType($zone_id);

        try {
            [$zone, $name] = $this->normalizeNameAndAssertAddAllowed($zone_id, $name, $type, $perm_edit);
        } catch (Exception $e) {
            return RecordWriteResult::forbidden($e->getMessage());
        }

        if (ZoneType::isReadOnly($zone_type) || !ZoneAccessPolicy::canEditZone($perm_edit, (bool)$user_is_zone_owner)) {
            return RecordWriteResult::forbidden(_("You do not have the permission to add a record to this zone."));
        }

        $dns_hostmaster = $this->config->get('dns', 'hostmaster');
        $dns_ttl = $this->config->get('dns', 'ttl');

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $content = $this->dnsFormatter->formatContent($type, $content);

        // Now validate the input with normalized name using the validation service
        $validationResult = $this->validationService->validateRecord(
            -1,
            $zone_id,
            $type,
            $content,
            $name,
            $prio,
            $ttl,
            $dns_hostmaster,
            (int)$dns_ttl
        );
        if (!$validationResult->isValid()) {
            return RecordWriteResult::failure($validationResult->getFirstError(), Refusal::INVALID_INPUT, $validationResult->getField());
        }

        // Extract validated values
        $validatedData = $validationResult->getData();
        $content = $validatedData['content'];
        $name = strtolower($validatedData['name']); // powerdns only searches for lower case records
        $validatedTtl = $validatedData['ttl'];
        $validatedPrio = $validatedData['prio'];

        // Create RecordRepository to check if record exists
        $recordRepository = $this->repositoryFactory->createRecordRepository();
        if ($recordRepository->recordExists($zone_id, $name, $type, $content)) {
            return RecordWriteResult::failure(_('A record with this hostname, type, and content already exists.'), Refusal::CONFLICT, RecordField::DUPLICATE);
        }

        // The row and the serial bump land together; a batch caller already holds its own.
        $ownTransaction = $finalizeZone && $this->backendProvider->supportsLocalWriteTransaction() && !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // Disabled records need the disabled flag persisted atomically with the
            // insert; the regular insert path has no disabled support.
            $recordId = $disabled
                ? $this->backendProvider->createRecordAtomic($zone_id, $name, $type, $content, $validatedTtl, $validatedPrio, $disabled, $comment)
                : $this->backendProvider->addRecordGetId($zone_id, $name, $type, $content, $validatedTtl, $validatedPrio, $comment);
            if ($recordId === null) {
                if ($ownTransaction) {
                    $this->db->rollBack();
                }
                return RecordWriteResult::backendFailure(_('Failed to add record to DNS backend.'));
            }

            $this->captureChange(function () use ($recordId, $zone_id, $name, $type, $content, $validatedTtl, $validatedPrio, $disabled): void {
                $zone_name = $this->domainRepository->getDomainNameById($zone_id);
                $this->changeLogger->logRecordCreate([
                    'id' => $recordId,
                    'name' => $name,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => $validatedTtl,
                    'prio' => $validatedPrio,
                    'disabled' => (bool)$disabled,
                    'zone_name' => is_string($zone_name) ? $zone_name : null,
                ], $zone_id);
            });

            if ($finalizeZone && $type != 'SOA') {
                $this->soaRecordManager->updateSOASerial($zone_id);
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($finalizeZone) {
            // The serial already moved inside the transaction above
            $this->finalizeZone($zone_id, false);
        }

        return RecordWriteResult::ok($recordId);
    }

    /**
     * Whether two stored copies of a record differ in any field that PowerDNS serves.
     *
     * @param array<string, mixed> $before Record row as stored before the edit
     * @param array<string, mixed> $after Record row as stored after the edit
     */
    public static function recordFieldsDiffer(array $before, array $after): bool
    {
        // Rows come back as strings from PDO and as ints from the API, so compare by value
        return strtolower((string)($before['name'] ?? '')) !== strtolower((string)($after['name'] ?? ''))
            || strtoupper((string)($before['type'] ?? '')) !== strtoupper((string)($after['type'] ?? ''))
            || (string)($before['content'] ?? '') !== (string)($after['content'] ?? '')
            || (int)($before['ttl'] ?? 0) !== (int)($after['ttl'] ?? 0)
            || (int)($before['prio'] ?? 0) !== (int)($after['prio'] ?? 0)
            || (int)($before['disabled'] ?? 0) !== (int)($after['disabled'] ?? 0);
    }

    /**
     * Edit a record
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param array $record Record structure to update
     */
    public function editRecord(array $record, bool $finalizeZone = true, ?array $comment = null): RecordWriteResult
    {
        $dns_hostmaster = $this->config->get('dns', 'hostmaster');
        $perm_edit = $this->editPermissionLevel();

        // Derive the zone from the record id; a caller-supplied zid could name an
        // owned zone to pass the ownership check while editing another zone's record.
        $recordRepository = $this->repositoryFactory->createRecordRepository();
        $recordDetails = $recordRepository->getRecordDetailsFromRecordId($record['rid']);
        if (empty($recordDetails)) {
            return RecordWriteResult::notFound(_("Record not found."));
        }
        $record['zid'] = (int)$recordDetails['zid'];

        $user_is_zone_owner = $this->userIsZoneOwner($record['zid']);
        $zone_type = $this->domainRepository->getDomainType($record['zid']);

        // Normalize the posted name first so the apex comparison below sees the FQDN
        $zone = $this->domainRepository->getDomainNameById($record['zid']);
        $hostnameValidator = new HostnameValidator(HostnamePolicy::fromConfig($this->config));
        $record['name'] = $hostnameValidator->normalizeRecordName($record['name'], $zone);

        // Both the stored record and the posted state must pass: a client-level
        // editor may neither touch a restricted record nor turn a record into one.
        $canEditSubzoneNs = $this->userHasPermission(Permission::PERM_EDIT_NS_SUBZONE);
        $storedIsRestricted = Permission::isRecordRestrictedForClient($recordDetails['type'], $perm_edit, $recordDetails['name'], $zone, $canEditSubzoneNs);
        $postedIsRestricted = Permission::isRecordRestrictedForClient($record['type'], $perm_edit, $record['name'], $zone, $canEditSubzoneNs);
        if ($storedIsRestricted || $postedIsRestricted) {
            // Name the type that actually triggered the refusal: reporting the posted
            // type would call a LUA-to-A retype an SOA denial.
            $refusedType = $storedIsRestricted ? $recordDetails['type'] : $record['type'];

            return RecordWriteResult::forbidden(Permission::restrictedRecordTypeMessage($refusedType, 'edit'));
        }

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $record['content'] = $this->dnsFormatter->formatContent($record['type'], $record['content']);

        $dns_ttl = $this->config->get('dns', 'ttl');

        if (ZoneType::isReadOnly($zone_type) || !ZoneAccessPolicy::canEditZone($perm_edit, (bool)$user_is_zone_owner)) {
            return RecordWriteResult::forbidden(_("You do not have permission to edit this record."));
        }

        // Now validate the input with normalized name using the validation service
        $validationResult = $this->validationService->validateRecord(
            $record['rid'],
            $record['zid'],
            $record['type'],
            $record['content'],
            $record['name'],
            (int)$record['prio'],
            (int)$record['ttl'],
            $dns_hostmaster,
            (int)$dns_ttl
        );
        if (!$validationResult->isValid()) {
            return RecordWriteResult::failure($validationResult->getFirstError(), Refusal::INVALID_INPUT, $validationResult->getField());
        }

        // Extract validated values
        $validatedData = $validationResult->getData();
        $content = $validatedData['content'];
        $name = strtolower($validatedData['name']); // powerdns only searches for lower case records
        $validatedTtl = $validatedData['ttl'];
        $validatedPrio = $validatedData['prio'];

        $submitted = [
            'name' => $name,
            'type' => $record['type'],
            'content' => $content,
            'ttl' => $validatedTtl,
            'prio' => $validatedPrio,
            'disabled' => $record['disabled'] ?? 0,
        ];
        // On the API backend the write itself makes PowerDNS bump the serial through
        // SOA-EDIT-API, so an opted-out install must not send an identical replacement
        if (
            !$this->config->get('dns', 'bump_serial_on_unchanged_save', true)
            && !self::recordFieldsDiffer($recordDetails, $submitted)
        ) {
            return RecordWriteResult::ok();
        }

        if (
            !$this->backendProvider->editRecord(
                $record['rid'],
                $name,
                $record['type'],
                $content,
                $validatedTtl,
                $validatedPrio,
                $record['disabled'],
                $comment
            )
        ) {
            return RecordWriteResult::backendFailure(_('Failed to update record in DNS backend.'));
        }

        $afterRecord = [
            'id' => $record['rid'],
            'name' => $name,
            'type' => $record['type'],
            'content' => $content,
            'ttl' => $validatedTtl,
            'prio' => $validatedPrio,
            'disabled' => $record['disabled'] ?? false,
            'zone_name' => is_string($zone) ? $zone : null,
        ];
        $beforeForLog = $recordDetails;
        $beforeForLog['id'] = $record['rid'];
        $beforeForLog['zone_name'] = is_string($zone) ? $zone : null;
        $this->captureChange(function () use ($beforeForLog, $afterRecord, $record): void {
            $this->changeLogger->logRecordEdit($beforeForLog, $afterRecord, $record['zid']);
        });

        if ($finalizeZone) {
            $this->finalizeZone((int)$record['zid'], $record['type'] !== 'SOA');
        }

        return RecordWriteResult::ok();
    }

    /**
     * Delete a record by a given record id
     *
     * @param int|string $rid Record ID
     */
    public function deleteRecord(int|string $rid, bool $finalizeZone = true): RecordWriteResult
    {
        $perm_edit = $this->editPermissionLevel();

        $recordRepository = $this->repositoryFactory->createRecordRepository();
        $record = $recordRepository->getRecordDetailsFromRecordId($rid);
        if (empty($record)) {
            return RecordWriteResult::notFound(_("Record not found."));
        }
        $user_is_zone_owner = $this->userIsZoneOwner($record['zid']);

        // Secondary and Consumer zones replicate records from a primary - records are read-only
        if (ZoneType::isReadOnly($this->domainRepository->getDomainType($record['zid']))) {
            return RecordWriteResult::forbidden(_("You cannot delete records from a read-only zone."));
        }

        if (!ZoneAccessPolicy::canEditZone($perm_edit, (bool)$user_is_zone_owner)) {
            return RecordWriteResult::forbidden(_("You do not have the permission to delete this record."));
        }

        $zone = $this->domainRepository->getDomainNameById($record['zid']);
        $canEditSubzoneNs = $this->userHasPermission(Permission::PERM_EDIT_NS_SUBZONE);
        if (Permission::isRecordRestrictedForClient($record['type'], $perm_edit, $record['name'], $zone, $canEditSubzoneNs)) {
            return RecordWriteResult::forbidden(Permission::restrictedRecordTypeMessage($record['type'], 'delete'));
        }

        if (!$this->backendProvider->deleteRecord($rid)) {
            return RecordWriteResult::backendFailure(_('Failed to delete record from DNS backend.'));
        }

        $this->captureChange(function () use ($record, $rid, $zone): void {
            $zoneId = isset($record['zid']) ? (int) $record['zid'] : null;
            $beforeForLog = $record;
            $beforeForLog['id'] = $rid;
            $beforeForLog['zone_name'] = is_string($zone) ? $zone : null;
            $this->changeLogger->logRecordDelete($beforeForLog, $zoneId);
        });

        // Nothing points at the row any more: the template link, the record's own
        // comment, and the RRset comment once no sibling record is left to carry it.
        $zoneId = (int)$record['zid'];
        $this->templateLinks->unlinkRecord($rid);
        $comments = $this->repositoryFactory->createRecordCommentRepository();
        $this->repositoryFactory->createRecordLinkedCommentRepository()?->deleteByRecordId($rid);
        if (!$recordRepository->hasSimilarRecords($zoneId, (string)$record['name'], (string)$record['type'], $rid)) {
            $comments->delete($zoneId, (string)$record['name'], (string)$record['type']);
        }

        if ($finalizeZone) {
            $this->finalizeZone($zoneId, $record['type'] !== 'SOA');
        }

        return RecordWriteResult::ok();
    }

    public function finalizeZone(int $zoneId, bool $bumpSerial = true): void
    {
        if ($bumpSerial) {
            $this->soaRecordManager->updateSOASerial($zoneId);
        }
        $zoneName = $this->domainRepository->getDomainNameById($zoneId);
        if (is_string($zoneName)) {
            $this->rectifyZone($zoneName);
        }
    }

    /**
     * Rectify after a write when DNSSEC is in use, so signatures cover the change.
     * The write is already committed, so a failure here is logged, not raised.
     */
    private function rectifyZone(string $zoneName): void
    {
        if (!$this->config->get('dnssec', 'enabled')) {
            return;
        }
        try {
            $this->builtDnssecProvider ??= ($this->dnssecProvider)();
            if (!$this->builtDnssecProvider->rectifyZone($zoneName)) {
                $this->logger->warning('Zone rectify refused for {zone}', ['zone' => $zoneName]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Zone rectify failed for {zone}: {error}', ['zone' => $zoneName, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Edit the zone comment
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param int $zone_id Zone ID
     * @param string $comment Comment to set
     *
     * @return RecordWriteResult Success, or the reason the write was refused
     */
    public function editZoneComment(int $zone_id, string $comment): RecordWriteResult
    {
        $perm_edit = $this->editPermissionLevel();

        $user_is_zone_owner = $this->userIsZoneOwner($zone_id);
        $zone_type = $this->domainRepository->getDomainType($zone_id);

        if (ZoneType::isReadOnly($zone_type) || !ZoneAccessPolicy::canEditZone($perm_edit, (bool)$user_is_zone_owner)) {
            return RecordWriteResult::forbidden(_("You do not have the permission to edit this comment."));
        }

        $this->repositoryFactory->createZoneRepository()->saveZoneComment($zone_id, $comment);

        return RecordWriteResult::ok();
    }
}
