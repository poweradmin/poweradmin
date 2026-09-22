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

use PDO;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Repository\TemplateRecordLinkRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateSyncRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RepositoryFactoryInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\Zone\ZoneAccountSyncService;
use Poweradmin\Domain\Service\Template\ZoneTemplatePlaceholders;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Error\ZoneCreationFailedException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Creates, updates and deletes zones for the web UI, including template records and DNSSEC setup.
 */
final class DomainManager implements DomainManagerInterface
{
    private PDO $db;
    private ConfigurationInterface $config;
    private DomainRepositoryInterface $domainRepository;
    private IPAddressValidator $ipAddressValidator;
    private DnsBackendProviderInterface $backendProvider;
    private LoggerInterface $logger;
    private RecordChangeWriterInterface $changeLogger;
    private PermissionService $permissionService;
    private UserLookupInterface $userRepository;
    private ActorInterface $actor;
    private ZoneTemplateRepositoryInterface $zoneTemplateRepository;
    private ZoneTemplatePlaceholders $placeholders;
    private RepositoryFactoryInterface $repositoryFactory;
    private ZoneTemplateApplier $templateApplier;
    private ZoneTemplateSyncRepositoryInterface $templateSync;
    private TemplateRecordLinkRepositoryInterface $templateLinks;
    private ZoneGroupRepositoryInterface $zoneGroups;

    /**
     * Constructor
     *
     * @param PDO $db Database connection, for the transaction around the native zone rows
     * @param ConfigurationInterface $config Configuration manager
     * @param DomainRepositoryInterface $domainRepository Domain repository
     * @param RepositoryFactoryInterface $repositoryFactory Builds the zone repository
     * @param DnsBackendProviderInterface $backendProvider DNS backend provider
     * @param PermissionService $permissionService Permissions and zone ownership of the acting user
     * @param UserLookupInterface $userRepository Resolves the users named as zone owners
     * @param RecordChangeWriterInterface $changeLogger Receives the zone and record snapshots
     * @param ZoneTemplateApplier $templateApplier Applies a template to an existing zone
     * @param ZoneTemplateRepositoryInterface $zoneTemplateRepository Reads the template records seeded into a new zone
     * @param ZoneTemplatePlaceholders $placeholders Expands the placeholders in those records
     * @param ZoneTemplateSyncRepositoryInterface $templateSync Records which template a new zone was seeded from
     * @param TemplateRecordLinkRepositoryInterface $templateLinks Links the seeded records back to their template
     * @param ZoneGroupRepositoryInterface $zoneGroups Group ownership of the new zone
     * @param ActorInterface $actor The user the ownership and permission checks are about
     */
    public function __construct(
        PDO $db,
        ConfigurationInterface $config,
        DomainRepositoryInterface $domainRepository,
        RepositoryFactoryInterface $repositoryFactory,
        DnsBackendProviderInterface $backendProvider,
        PermissionService $permissionService,
        UserLookupInterface $userRepository,
        RecordChangeWriterInterface $changeLogger,
        ZoneTemplateApplier $templateApplier,
        ZoneTemplateRepositoryInterface $zoneTemplateRepository,
        ZoneTemplatePlaceholders $placeholders,
        ZoneTemplateSyncRepositoryInterface $templateSync,
        TemplateRecordLinkRepositoryInterface $templateLinks,
        ZoneGroupRepositoryInterface $zoneGroups,
        ActorInterface $actor,
        ?LoggerInterface $logger = null
    ) {
        $this->templateLinks = $templateLinks;
        $this->zoneGroups = $zoneGroups;
        $this->templateApplier = $templateApplier;
        $this->zoneTemplateRepository = $zoneTemplateRepository;
        $this->placeholders = $placeholders;
        $this->templateSync = $templateSync;
        $this->repositoryFactory = $repositoryFactory;
        $this->db = $db;
        $this->config = $config;
        $this->domainRepository = $domainRepository;
        $this->ipAddressValidator = new IPAddressValidator();
        $this->backendProvider = $backendProvider;
        $this->permissionService = $permissionService;
        $this->userRepository = $userRepository;
        $this->logger = $logger ?? new NullLogger();
        $this->changeLogger = $changeLogger;
        $this->actor = $actor;
    }

    private function captureChange(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->logger->warning('Failed to write zone change log: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if the acting user owns the zone directly or via group membership.
     */
    private function currentUserOwnsZone(int $zoneId): bool
    {
        $userId = $this->actor->userId();
        if ($userId === null) {
            return false;
        }
        return $this->permissionService->userOwnsZone($userId, $zoneId);
    }

    /**
     * Check if the acting user has the given permission (admins always pass)
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
     * Snapshot a zone's metadata-relevant fields (type and master IP) for the
     * change log's metadata-edit entries.
     *
     * Uses the backend provider's lightweight zone lookup rather than the
     * repository's getZoneInfoFromId(), which aggregates record_count and
     * would turn a metadata edit into O(records) work on large zones.
     *
     * @return array{id:int,name:?string,type:?string,master:?string}|null
     */
    private function snapshotZoneMetadataForLog(int $zoneId): ?array
    {
        try {
            $zone = $this->backendProvider->getZoneById($zoneId);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to snapshot zone for change log: {error}', ['error' => $e->getMessage()]);
            return null;
        }

        if ($zone === null || empty($zone['name'])) {
            return null;
        }

        return [
            'id' => $zoneId,
            'name' => $zone['name'],
            'type' => $zone['type'] ?? null,
            'master' => $zone['master'] ?? null,
        ];
    }

    /**
     * Add a domain to the database
     *
     * @param string $domain A domain name
     * @param int|null $owner Owner ID for domain (null if only groups are assigned)
     * @param string $type Type of domain ['NATIVE','MASTER','SLAVE','PRODUCER','CONSUMER']
     * @param string $slave_master Master server hostname, required for kinds that replicate from a primary
     * @param int|string $zone_template ID of zone template ['none' or int]
     * @param array $groupIds Group IDs to assign as zone owners
     * @param string|null $soaEditApi SOA-EDIT-API policy for the new zone; 'OFF' disables, null uses the dns.soa_edit_api config default
     */
    public function addDomain(string $domain, ?int $owner, string $type, string $slave_master, int|string $zone_template, array $groupIds = [], ?string $soaEditApi = null): ZoneWriteResult
    {
        // Last-resort guard: not every caller whitelists the kind, and an unknown
        // string would otherwise be written straight into the zone type.
        if (!in_array(strtoupper($type), ZoneType::getAllTypes(), true)) {
            return ZoneWriteResult::failure(_('Invalid or unexpected input given.'));
        }

        // TODO: make sure only one is possible if only one is enabled
        if (!$this->userHasPermission(Permission::PERM_ZONE_MASTER_ADD) && !$this->userHasPermission(Permission::PERM_ZONE_SLAVE_ADD)) {
            return ZoneWriteResult::forbidden(_("You do not have the permission to add a master zone."));
        }

        // Keeps the original string for MASTER/NATIVE zones, which pass '' here, and for
        // anything that fails validation - addDomain has never validated this argument.
        $slave_master = $this->normalizeMasterList($slave_master) ?? $slave_master;
        $replicates = ZoneType::replicatesFromPrimary($type);

        // A replicating kind is inert without a primary, so require one rather
        // than letting the template slot alone satisfy the guard.
        $hasRequiredArgs = $replicates
            ? (bool)($domain && $slave_master)
            : (bool)($domain && $zone_template);
        if (!$hasRequiredArgs) {
            return ZoneWriteResult::failure(sprintf(_('Invalid argument(s) given to function %s'), "addDomain"));
        }

        // Create the zone before the outer transaction: in API mode
        // createZone() commits its own placeholder row when no transaction is open.
        try {
            $domain_id = $this->backendProvider->createZone($domain, $type, $slave_master);
        } catch (\Exception $e) {
            return ZoneWriteResult::backendFailure(sprintf(_('Failed to create zone: %s'), $e->getMessage()));
        }
        if ($domain_id === false) {
            // API call was rejected (duplicate zone, validation error, etc.)
            // Zone was NOT created - do not attempt cleanup as it could
            // delete an existing zone with the same name.
            return ZoneWriteResult::backendFailure(_('Failed to create zone in DNS backend.'));
        }

        if (!$replicates) {
            $this->applySerialPolicy($domain_id, $domain, $soaEditApi);
        }

        $this->db->beginTransaction();
        try {
            $zone_id = $this->createZoneShell($domain_id, $owner, $zone_template);
            $this->assignInitialOwnership($domain_id, $zone_id, $owner, $zone_template, $groupIds);

            $zoneLog = ['id' => $domain_id, 'name' => $domain, 'type' => $type];
            if ($replicates) {
                // Records arrive by transfer, so skip the apex SOA and any template
                // records. Master IP is already set by backendProvider->createZone().
                $this->db->commit();
                $zoneLog['master'] = $slave_master;
            } else {
                $zoneLog += $this->seedZoneRecords($domain_id, $domain, $zone_template);
            }
            $zoneLog['owner'] = $owner;

            $this->captureChange(function () use ($zoneLog): void {
                $this->changeLogger->logZoneCreate($zoneLog);
            });
            return ZoneWriteResult::ok((int)$domain_id);
        } catch (ZoneCreationFailedException $e) {
            $this->cleanupFailedCreation($domain_id, $domain);
            return ZoneWriteResult::backendFailure($e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error('Zone creation for {domain} failed: {error}', ['domain' => $domain, 'error' => $e->getMessage()]);
            $this->cleanupFailedCreation($domain_id, $domain);
            return ZoneWriteResult::backendFailure(sprintf(_('Failed to create zone: %s'), $e->getMessage()));
        }
    }

    /**
     * Write the native zones row for a freshly created backend zone.
     *
     * @return int|string The zones.id the template sync rows key on
     */
    private function createZoneShell(int $domain_id, ?int $owner, int|string $zone_template): int|string
    {
        $templateId = ($zone_template == "none") ? 0 : (int)$zone_template;

        return $this->repositoryFactory->createZoneRepository()->createZoneShell($domain_id, $owner, $templateId);
    }

    /**
     * Push the owner account, register the template sync state and add the group owners.
     *
     * @param int[] $groupIds
     */
    private function assignInitialOwnership(int $domain_id, int|string $zone_id, ?int $owner, int|string $zone_template, array $groupIds): void
    {
        // Ownerless zones keep their default empty account; no push needed on create
        if ($owner !== null) {
            $accountSync = new ZoneAccountSyncService($this->db, $this->config, $this->backendProvider);
            $accountSync->syncZoneAccount($domain_id);
        }

        if ($zone_template != "none" && is_numeric($zone_template)) {
            $this->templateSync->createSyncRecord((int)$zone_id, (int)$zone_template);
            // Mark as synced since we're creating from template
            $this->templateSync->markZoneAsSynced((int)$zone_id, (int)$zone_template);
        }

        foreach (array_unique($groupIds) as $groupId) {
            $this->zoneGroups->add($domain_id, (int)$groupId);
        }
    }

    /**
     * Seed the apex records of a primary zone: the default SOA, or the template's records.
     *
     * @return array<string, mixed> Extra fields for the zone-create log entry
     * @throws ZoneCreationFailedException when a record cannot be written
     */
    private function seedZoneRecords(int $domain_id, string $domain, int|string $zone_template): array
    {
        $seedsDefaults = $zone_template == "none" && $domain_id;
        if (!$seedsDefaults && !($domain_id && is_numeric($zone_template))) {
            throw new ZoneCreationFailedException(sprintf(_('Invalid argument(s) given to function %s %s'), "addDomain", "could not create zone"));
        }

        // A backend write that cannot join this transaction lands zones + zones_groups
        // first; on SQLite the open lock would otherwise block PowerDNS.
        $localTransaction = $this->backendProvider->supportsLocalWriteTransaction();
        if (!$localTransaction) {
            $this->db->commit();
        }

        if ($seedsDefaults) {
            $this->seedDefaultSoa($domain_id, $domain);
            $logFields = [];
        } else {
            $this->materialiseTemplate($domain_id, $domain, (int)$zone_template);
            $logFields = ['template_id' => (int)$zone_template];
        }

        if ($localTransaction) {
            $this->db->commit();
        }

        return $logFields;
    }

    /**
     * Write the apex SOA built from the dns.* defaults.
     *
     * @throws ZoneCreationFailedException
     */
    private function seedDefaultSoa(int $domain_id, string $domain): void
    {
        $ns1 = $this->config->get('dns', 'ns1');
        $hm = $this->config->get('dns', 'hostmaster');
        $ttl = $this->config->get('dns', 'ttl');
        $soa_refresh = $this->config->get('dns', 'soa_refresh', 28800);
        $soa_retry = $this->config->get('dns', 'soa_retry', 7200);
        $soa_expire = $this->config->get('dns', 'soa_expire', 604800);
        $soa_minimum = $this->config->get('dns', 'soa_minimum', 86400);
        $serial = date("Ymd") . "00";

        $soa_content = "$ns1 $hm $serial $soa_refresh $soa_retry $soa_expire $soa_minimum";

        if (!$this->backendProvider->addRecord($domain_id, $domain, 'SOA', $soa_content, (int)$ttl, 0)) {
            throw new ZoneCreationFailedException(_('Failed to create SOA record for zone.'));
        }
    }

    /**
     * Write the template's records with placeholders resolved and link each
     * one back to the template.
     *
     * @throws ZoneCreationFailedException
     */
    private function materialiseTemplate(int $domain_id, string $domain, int $zone_template): void
    {
        $templ_records = $this->zoneTemplateRepository->getZoneTemplateRecords($zone_template);
        if (empty($templ_records)) {
            return;
        }

        $dns_ttl = $this->config->get('dns', 'ttl');

        foreach ($templ_records as $r) {
            if (!ZoneTemplateApplier::shouldApplyTemplateRecord($domain, $r["type"])) {
                continue;
            }

            // A template name without [ZONE] is relative to the zone, like a form entry
            $name = DnsHelper::restoreZoneSuffix($this->placeholders->parseTemplateValue($r["name"], $domain), $domain);
            $recordType = $r["type"];
            $content = $this->placeholders->parseTemplateValue($r["content"], $domain, $recordType);
            $ttl = $r["ttl"] ?: $dns_ttl;
            $prio = intval($r["prio"]);

            $record_id = $this->backendProvider->addRecordGetId($domain_id, $name, $recordType, $content, (int)$ttl, $prio);
            if ($record_id === null) {
                throw new ZoneCreationFailedException(sprintf(_('Failed to create %s record for zone.'), $recordType));
            }

            // Linked so a later template edit can remove exactly these records.
            $this->templateLinks->linkRecord($domain_id, $record_id, (int)$r['zone_templ_id']);
        }
    }

    /**
     * Undo a creation that failed after the backend zone existed: roll back
     * whatever is still open, delete the backend zone, then remove any
     * metadata that was already committed (a no-op after a rollback).
     */
    private function cleanupFailedCreation(int $domain_id, string $domain): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        $this->cleanupZoneOnFailure($domain_id, $domain);
        $this->cleanupZoneMetadata($domain_id);
    }

    /**
     * Apply the SOA serial policy (SOA-EDIT / SOA-EDIT-API) to a new zone.
     *
     * The per-zone SOA-EDIT-API choice wins over the dns.soa_edit_api config
     * default; 'OFF' explicitly disables the policy (in API mode this clears
     * the server-applied default). SOA-EDIT comes from dns.soa_edit only.
     * Values are validated against the same choice sets the UI offers, so
     * config restrictions hold everywhere. Failures are logged but never
     * fail zone creation.
     */
    private function applySerialPolicy(int $domainId, string $domain, ?string $soaEditApi): void
    {
        if ($soaEditApi === null || $soaEditApi === '') {
            $soaEditApi = (string)$this->config->get('dns', 'soa_edit_api', '');
        }
        $soaEdit = (string)$this->config->get('dns', 'soa_edit', '');

        $properties = [];

        if ($soaEditApi !== '') {
            if (in_array($soaEditApi, MetadataDefinitions::getSoaEditApiChoices($this->config), true)) {
                $properties['soa_edit_api'] = $soaEditApi === MetadataDefinitions::SOA_EDIT_API_OFF ? '' : $soaEditApi;
            } else {
                $this->logger->warning('Ignoring invalid or not offered SOA-EDIT-API value: {value}', ['value' => $soaEditApi]);
            }
        }

        if ($soaEdit !== '') {
            if (in_array($soaEdit, MetadataDefinitions::getOfferedOptions('SOA-EDIT', $this->config) ?? [], true)) {
                $properties['soa_edit'] = $soaEdit;
            } else {
                $this->logger->warning('Ignoring invalid or not offered dns.soa_edit value: {value}', ['value' => $soaEdit]);
            }
        }

        if ($properties === []) {
            return;
        }

        try {
            if (!$this->backendProvider->setZoneSerialPolicy($domainId, $domain, $properties)) {
                $this->logger->warning('Failed to set SOA serial policy on new zone {id}', ['id' => $domainId]);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to set SOA serial policy on new zone {id}: {error}', ['id' => $domainId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Attempt to clean up a zone created before the transaction on failure.
     *
     * Since createZone() runs outside the transaction (required for API mode
     * snapshot isolation), both SQL and API backends need compensating cleanup
     * when the subsequent transaction fails.
     */
    private function cleanupZoneOnFailure(int $domainId, string $domain): void
    {
        try {
            $this->backendProvider->deleteZone($domainId, $domain);
        } catch (\Exception $e) {
            $this->logger->error('Failed to clean up orphaned zone {domain} after local failure: {error}', ['domain' => $domain, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Clean up already-committed Poweradmin metadata for a zone.
     * Used when the transaction was committed early (API backend)
     * and a subsequent step fails.
     */
    private function cleanupZoneMetadata(int $domainId): void
    {
        try {
            $this->templateLinks->unlinkZone($domainId);
            $this->zoneGroups->removeAllForDomain($domainId);
            $this->repositoryFactory->createZoneRepository()->deleteZoneShell($domainId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to clean up zone metadata for domain_id {domainId}: {error}', ['domainId' => $domainId, 'error' => $e->getMessage()]);
        }
    }

    private function userCanEditZoneContent(int $zoneId): bool
    {
        if ($this->userHasPermission(Permission::PERM_ZONE_CONTENT_EDIT_OTHERS)) {
            return true;
        }
        return $this->userHasPermission(Permission::PERM_ZONE_CONTENT_EDIT_OWN) && $this->currentUserOwnsZone($zoneId);
    }

    private function userCanEditZoneMetadata(int $zoneId): bool
    {
        if ($this->userHasPermission(Permission::PERM_ZONE_META_EDIT_OTHERS)) {
            return true;
        }
        if (
            $this->userHasPermission(Permission::PERM_ZONE_META_EDIT_OWN)
            && $this->currentUserOwnsZone($zoneId)
        ) {
            return true;
        }
        return false;
    }

    /**
     * Request an immediate AXFR transfer of a secondary zone from its master.
     *
     * @param int $id Zone ID
     */
    public function retrieveZone(int $id): bool
    {
        return $this->backendProvider->retrieveZone($id);
    }

    /**
     * Change Zone Type
     *
     * @param string $type New Zone Type [NATIVE,MASTER,SLAVE]
     * @param int $id Zone ID
     */
    public function changeZoneType(string $type, int $id): ZoneWriteResult
    {
        if (!$this->userCanEditZoneMetadata($id)) {
            return ZoneWriteResult::forbidden(_('You do not have the permission to edit zone metadata.'));
        }

        $beforeZone = $this->snapshotZoneMetadataForLog($id);

        if (!$this->backendProvider->updateZoneType($id, $type)) {
            return ZoneWriteResult::backendFailure(_('Failed to update zone type in DNS backend.'));
        }

        if ($beforeZone !== null) {
            $afterZone = $this->snapshotZoneMetadataForLog($id) ?? array_merge($beforeZone, ['type' => $type]);
            $this->captureChange(function () use ($beforeZone, $afterZone): void {
                $this->changeLogger->logZoneMetadataEdit($beforeZone, $afterZone);
            });
        }

        return ZoneWriteResult::ok($id);
    }

    /**
     * Collapse a master list to the one spelling the DomainManager write paths store.
     *
     * The forms submit several masters as one comma-separated string, and the v2 API
     * normalizes its own copy the same way, so agreeing on one spelling here stops a zone
     * sync from rewriting the row purely over spacing. Returns null when the list does not
     * validate, which lets addDomain() pass the original through - it does not validate its
     * input today and must not start failing here.
     */
    private function normalizeMasterList(string $masters): ?string
    {
        $validation = $this->ipAddressValidator->validateMultipleIPs($masters);

        return $validation->isValid() ? implode(',', $validation->getData()) : null;
    }

    /**
     * Change Slave Zone's Master IP Address
     *
     * @param int $zone_id Zone ID
     * @param string $ip_slave_master Master IP Address
     */
    public function changeZoneSlaveMaster(int $zone_id, string $ip_slave_master): ZoneWriteResult
    {
        if (!$this->userCanEditZoneMetadata($zone_id)) {
            return ZoneWriteResult::forbidden(_('You do not have the permission to edit zone metadata.'));
        }

        $normalized = $this->normalizeMasterList($ip_slave_master);
        if ($normalized === null) {
            return ZoneWriteResult::failure(sprintf(_('Invalid argument(s) given to function %s %s'), "changeZoneSlaveMaster", "This is not a valid IPv4 or IPv6 address: $ip_slave_master"));
        }

        $ip_slave_master = $normalized;

        $beforeZone = $this->snapshotZoneMetadataForLog($zone_id);

        if (!$this->backendProvider->updateZoneMaster($zone_id, $ip_slave_master)) {
            return ZoneWriteResult::backendFailure(_('Failed to update zone master in DNS backend.'));
        }

        if ($beforeZone !== null) {
            $afterZone = $this->snapshotZoneMetadataForLog($zone_id) ?? array_merge($beforeZone, ['master' => $ip_slave_master]);
            $this->captureChange(function () use ($beforeZone, $afterZone): void {
                $this->changeLogger->logZoneMetadataEdit($beforeZone, $afterZone);
            });
        }

        return ZoneWriteResult::ok($zone_id);
    }

    /**
     * Add a user as an owner of a zone. An existing owner is left as is and
     * reported as success.
     */
    public function addOwnerToZone(int $zone_id, int $user_id): ZoneWriteResult
    {
        if (!$this->userCanEditZoneMetadata($zone_id)) {
            return ZoneWriteResult::forbidden(_('You do not have the permission to edit zone metadata.'));
        }
        if ($this->userRepository->getUserById($user_id) === null) {
            return ZoneWriteResult::failure(sprintf(_('Unknown user ID: %s'), $user_id), Refusal::NOT_FOUND);
        }

        $zoneRepository = $this->repositoryFactory->createZoneRepository();
        if (!$zoneRepository->isUserZoneOwner($zone_id, $user_id) && !$zoneRepository->addOwnerToZone($zone_id, $user_id)) {
            return ZoneWriteResult::backendFailure(_('Failed to add the owner to the zone.'));
        }

        return ZoneWriteResult::ok($zone_id);
    }

    /**
     * Apply a zone template to a zone, or unlink it with template id 0.
     *
     * @param int $dns_ttl Default TTL
     * @param int $zone_id Zone ID to update
     * @param int $zone_template_id Zone Template ID to use for update
     */
    public function updateZoneRecords(int $dns_ttl, int $zone_id, int $zone_template_id): ZoneWriteResult
    {
        // Secondary and Consumer zones replicate from a primary - applying a
        // template would write replicated records, so skip them entirely
        if (ZoneType::isReadOnly($this->domainRepository->getDomainType($zone_id))) {
            return ZoneWriteResult::ok($zone_id);
        }

        // Template records (SOA and NS included) are written straight to the backend,
        // so applying one needs the same standing as editing those records by hand.
        if ($zone_template_id != 0 && !$this->userCanEditZoneContent($zone_id)) {
            return ZoneWriteResult::forbidden(_('You do not have permission to edit this zone.'));
        }

        $canAddZones = $this->userHasPermission(Permission::PERM_ZONE_MASTER_ADD)
            || $this->userHasPermission(Permission::PERM_ZONE_SLAVE_ADD);

        return $this->templateApplier->applyTemplate($zone_id, $zone_template_id, $dns_ttl, $canAddZones);
    }
}
