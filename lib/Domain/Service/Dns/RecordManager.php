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

use Exception;
use PDO;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Domain\Error\RecordIdNotFoundException;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Service\MessageService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Poweradmin\Domain\Enum\AccessScope;
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;

/**
 * Service class for managing DNS records
 */
class RecordManager implements RecordManagerInterface
{
    private PDO $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private DnsFormatter $dnsFormatter;
    private DnsRecordValidationServiceInterface $validationService;
    private SOARecordManagerInterface $soaRecordManager;
    private DomainRepositoryInterface $domainRepository;
    private DnsBackendProvider $backendProvider;
    private LoggerInterface $logger;
    private RecordChangeLogger $changeLogger;
    private ?PermissionService $permissionService = null;
    private UserContextService $userContext;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param ConfigurationManager $config Configuration manager
     * @param DnsRecordValidationServiceInterface $validationService DNS record validation service
     * @param SOARecordManagerInterface $soaRecordManager SOA record manager
     * @param DomainRepositoryInterface $domainRepository Domain repository
     * @param DnsBackendProvider|null $backendProvider DNS backend provider (auto-created if null)
     */
    public function __construct(
        PDO $db,
        ConfigurationManager $config,
        DnsRecordValidationServiceInterface $validationService,
        SOARecordManagerInterface $soaRecordManager,
        DomainRepositoryInterface $domainRepository,
        ?DnsBackendProvider $backendProvider = null,
        ?LoggerInterface $logger = null,
        ?RecordChangeLogger $changeLogger = null,
        ?UserContextService $userContext = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->dnsFormatter = new DnsFormatter($config);
        $this->validationService = $validationService;
        $this->soaRecordManager = $soaRecordManager;
        $this->domainRepository = $domainRepository;
        $this->backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        $this->logger = $logger ?? new NullLogger();
        $this->changeLogger = $changeLogger ?? new RecordChangeLogger($db);
        $this->userContext = $userContext ?? new UserContextService();
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
     * Check if the logged-in user owns the zone directly or via group membership
     */
    private function userIsZoneOwner(int $zoneId): bool
    {
        $userId = $this->userContext->getLoggedInUserId();
        if ($userId === null) {
            return false;
        }
        $userRepository = new DbUserRepository($this->db, $this->config);
        return $userRepository->userOwnsZone($userId, $zoneId);
    }

    /**
     * Check if the logged-in user has the given permission (admins always pass).
     * Memoized service keeps bulk record loops at one permission lookup.
     */
    private function userHasPermission(string $permission): bool
    {
        $userId = $this->userContext->getLoggedInUserId();
        if ($userId === null) {
            return false;
        }
        $this->permissionService ??= new PermissionService(new DbUserRepository($this->db, $this->config));
        return $this->permissionService->hasPermission($userId, $permission);
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
        $hostnameValidator = new HostnameValidator($this->config);
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
     * @return boolean true if successful
     */
    public function addRecord(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio): bool
    {
        // Callers on this signature still read failures from MessageService.
        $result = $this->addRecordGetId($zone_id, $name, $type, $content, $ttl, $prio);
        if (!$result->success) {
            $this->messageService->addSystemError((string)$result->message);
        }

        return $result->success;
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
     *
     * @return RecordWriteResult Carries the new record id, or the reason it was refused
     */
    public function addRecordGetId(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio, int $disabled = 0, bool $finalizeZone = true): RecordWriteResult
    {
        $perm_edit = Permission::getEditPermission($this->db, $this->config);

        $user_is_zone_owner = $this->userIsZoneOwner($zone_id);
        $zone_type = $this->domainRepository->getDomainType($zone_id);

        try {
            [$zone, $name] = $this->normalizeNameAndAssertAddAllowed($zone_id, $name, $type, $perm_edit);
        } catch (Exception $e) {
            return RecordWriteResult::forbidden($e->getMessage());
        }

        if (ZoneType::isReadOnly($zone_type) || $perm_edit == "none" || (AccessScope::fromString($perm_edit)->isOwnedOnly() && $user_is_zone_owner == "0")) {
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
            return RecordWriteResult::failure($validationResult->getFirstError());
        }

        // Extract validated values
        $validatedData = $validationResult->getData();
        $content = $validatedData['content'];
        $name = strtolower($validatedData['name']); // powerdns only searches for lower case records
        $validatedTtl = $validatedData['ttl'];
        $validatedPrio = $validatedData['prio'];

        // Create RecordRepository to check if record exists
        $recordRepository = (new \Poweradmin\Application\Service\RepositoryFactory($this->db, $this->config, $this->backendProvider))->createRecordRepository();
        if ($recordRepository->recordExists($zone_id, $name, $type, $content)) {
            return RecordWriteResult::failure(_('A record with this hostname, type, and content already exists.'), 409, RecordWriteResult::FIELD_DUPLICATE);
        }

        // On the SQL backend the row and the serial bump land together. The API
        // backend polls for the new id, which an open transaction would hide, and a
        // batch caller already holds its own.
        $ownTransaction = $finalizeZone && !$this->backendProvider->isApiBackend() && !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            try {
                // Disabled records need the disabled flag persisted atomically with the
                // insert; the regular insert path has no disabled support.
                $recordId = $disabled
                    ? $this->backendProvider->createRecordAtomic($zone_id, $name, $type, $content, $validatedTtl, $validatedPrio, $disabled)
                    : $this->backendProvider->addRecordGetId($zone_id, $name, $type, $content, $validatedTtl, $validatedPrio);
            } catch (RecordIdNotFoundException $e) {
                $this->logger->error('Failed to get record ID after creation: {error}', ['error' => $e->getMessage()]);
                $recordId = null;
            }
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
    public function editRecord(array $record, bool $finalizeZone = true): RecordWriteResult
    {
        $dns_hostmaster = $this->config->get('dns', 'hostmaster');
        $perm_edit = Permission::getEditPermission($this->db, $this->config);

        // Derive the zone from the record id; a caller-supplied zid could name an
        // owned zone to pass the ownership check while editing another zone's record.
        $recordRepository = (new RepositoryFactory($this->db, $this->config, $this->backendProvider))->createRecordRepository();
        $recordDetails = $recordRepository->getRecordDetailsFromRecordId($record['rid']);
        if (empty($recordDetails)) {
            return RecordWriteResult::notFound(_("Record not found."));
        }
        $record['zid'] = (int)$recordDetails['zid'];

        $user_is_zone_owner = $this->userIsZoneOwner($record['zid']);
        $zone_type = $this->domainRepository->getDomainType($record['zid']);

        // Normalize the posted name first so the apex comparison below sees the FQDN
        $zone = $this->domainRepository->getDomainNameById($record['zid']);
        $hostnameValidator = new HostnameValidator($this->config);
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

        if (ZoneType::isReadOnly($zone_type) || $perm_edit == "none" || (AccessScope::fromString($perm_edit)->isOwnedOnly() && $user_is_zone_owner == "0")) {
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
            return RecordWriteResult::failure($validationResult->getFirstError());
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
                $record['disabled']
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
        $perm_edit = Permission::getEditPermission($this->db, $this->config);

        $repositoryFactory = new RepositoryFactory($this->db, $this->config, $this->backendProvider);
        $recordRepository = $repositoryFactory->createRecordRepository();
        $record = $recordRepository->getRecordDetailsFromRecordId($rid);
        if (empty($record)) {
            return RecordWriteResult::notFound(_("Record not found."));
        }
        $user_is_zone_owner = $this->userIsZoneOwner($record['zid']);

        // Secondary and Consumer zones replicate records from a primary - records are read-only
        if (ZoneType::isReadOnly($this->domainRepository->getDomainType($record['zid']))) {
            return RecordWriteResult::forbidden(_("You cannot delete records from a read-only zone."));
        }

        if (!($perm_edit == "all" || (AccessScope::fromString($perm_edit)->isOwnedOnly() && $user_is_zone_owner == "1"))) {
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
        self::deleteRecordZoneTempl($this->db, $rid);
        $comments = $repositoryFactory->createRecordCommentRepository();
        $comments->deleteByRecordId($rid);
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
            $dnssecProvider = DnssecProviderFactory::create(
                $this->db,
                $this->config,
                DnsBackendProviderFactory::apiClientFrom($this->backendProvider)
            );
            if (!$dnssecProvider->rectifyZone($zoneName)) {
                $this->logger->warning('Zone rectify refused for {zone}', ['zone' => $zoneName]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Zone rectify failed for {zone}: {error}', ['zone' => $zoneName, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Delete record reference to zone template
     *
     * @param int|string $rid Record ID
     *
     * @return boolean true on success
     */
    public static function deleteRecordZoneTempl($db, int|string $rid): bool
    {
        // SQL record IDs live in records_zone_templ; API record IDs (encoded
        // RecordIdentifier strings) live in records_zone_templ_api. PostgreSQL
        // rejects encoded strings against the integer record_id column, so
        // dispatch by ID type instead of probing both tables.
        if (is_int($rid) || ctype_digit($rid)) {
            $stmt = $db->prepare("DELETE FROM records_zone_templ WHERE record_id = ?");
            $stmt->execute([(int)$rid]);
        } else {
            $stmt = $db->prepare("DELETE FROM records_zone_templ_api WHERE record_id = ?");
            $stmt->execute([$rid]);
        }

        return true;
    }

    /**
     * Get Zone comment
     *
     * @param int $zone_id Zone ID
     *
     * @return string Zone Comment
     */
    public static function getZoneComment($db, int $zone_id): string
    {
        $stmt = $db->prepare("SELECT comment FROM zones WHERE " . CanonicalZoneSql::canonicalIdColumn() . " = ?");
        $stmt->bindValue(1, $zone_id, PDO::PARAM_INT);
        $stmt->execute();
        $comment = $stmt->fetchColumn();

        return $comment ?: '';
    }

    /**
     * Edit the zone comment
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param int $zone_id Zone ID
     * @param string $comment Comment to set
     *
     * @return boolean true on success
     */
    public function editZoneComment(int $zone_id, string $comment): bool
    {
        $perm_edit = Permission::getEditPermission($this->db, $this->config);

        $user_is_zone_owner = $this->userIsZoneOwner($zone_id);
        $zone_type = $this->domainRepository->getDomainType($zone_id);

        if (ZoneType::isReadOnly($zone_type) || $perm_edit == "none" || (AccessScope::fromString($perm_edit)->isOwnedOnly() && $user_is_zone_owner == "0")) {
            $this->messageService->addSystemError(_("You do not have the permission to edit this comment."));

            return false;
        } else {
            $query = "SELECT COUNT(*) FROM zones WHERE domain_id = :zone_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
            $stmt->execute();

            $count = $stmt->fetchColumn();

            if ($count > 0) {
                $query = "UPDATE zones SET comment = :comment WHERE domain_id = :zone_id";
            } else {
                $query = "INSERT INTO zones (domain_id, owner, comment, zone_templ_id) VALUES (:zone_id, 1, :comment, 0)";
            }
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
            $stmt->bindValue(':comment', $comment, PDO::PARAM_STR);
            $stmt->execute();
        }
        return true;
    }
}
