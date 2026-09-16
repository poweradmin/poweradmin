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
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneAccountSyncService;
use Poweradmin\Domain\Service\ZoneTemplateSyncService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Poweradmin\Domain\Service\ZoneAccessPolicy;

/**
 * Creates, updates and deletes zones for the web UI, including template records and DNSSEC setup.
 */
class DomainManager implements DomainManagerInterface
{
    private PDO $db;
    private ConfigurationInterface $config;
    private SOARecordManagerInterface $soaRecordManager;
    private DomainRepositoryInterface $domainRepository;
    private IPAddressValidator $ipAddressValidator;
    private DnsBackendProviderInterface $backendProvider;
    private LoggerInterface $logger;
    private RecordChangeLogger $changeLogger;
    private ?PermissionService $permissionService = null;
    private ?DbUserRepository $userRepository = null;
    private UserContextService $userContext;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param ConfigurationInterface $config Configuration manager
     * @param SOARecordManagerInterface $soaRecordManager SOA record manager
     * @param DomainRepositoryInterface $domainRepository Domain repository
     * @param DnsBackendProviderInterface|null $backendProvider DNS backend provider (auto-created if null)
     */
    public function __construct(
        PDO $db,
        ConfigurationInterface $config,
        SOARecordManagerInterface $soaRecordManager,
        DomainRepositoryInterface $domainRepository,
        ?DnsBackendProviderInterface $backendProvider = null,
        ?LoggerInterface $logger = null,
        ?RecordChangeLogger $changeLogger = null,
        ?UserContextService $userContext = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->soaRecordManager = $soaRecordManager;
        $this->domainRepository = $domainRepository;
        $this->ipAddressValidator = new IPAddressValidator();
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
            $this->logger->warning('Failed to write zone change log: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if the logged-in user owns the zone directly or via group membership.
     */
    private function currentUserOwnsZone(int $zoneId): bool
    {
        $userId = $this->userContext->getLoggedInUserId();
        if ($userId === null) {
            return false;
        }
        return $this->userRepository()->userOwnsZone($userId, $zoneId);
    }

    /**
     * Check if the logged-in user has the given permission (admins always pass)
     */
    private function userHasPermission(string $permission): bool
    {
        $userId = $this->userContext->getLoggedInUserId();
        if ($userId === null) {
            return false;
        }
        $this->permissionService ??= new PermissionService($this->userRepository());
        return $this->permissionService->hasPermission($userId, $permission);
    }

    private function userRepository(): DbUserRepository
    {
        return $this->userRepository ??= new DbUserRepository($this->db, $this->config);
    }

    /**
     * Snapshot a zone's metadata-relevant fields (type and master IP) for the
     * change log. Distinct from {@see snapshotZoneForLog()}, which only carries
     * id/name/type and is used for zone create/delete entries.
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
     * @param object $db Database connection
     * @param string $domain A domain name
     * @param int|null $owner Owner ID for domain (null if only groups are assigned)
     * @param string $type Type of domain ['NATIVE','MASTER','SLAVE','PRODUCER','CONSUMER']
     * @param string $slave_master Master server hostname, required for kinds that replicate from a primary
     * @param int|string $zone_template ID of zone template ['none' or int]
     * @param array $groupIds Group IDs to assign as zone owners
     * @param string|null $soaEditApi SOA-EDIT-API policy for the new zone; 'OFF' disables, null uses the dns.soa_edit_api config default
     */
    public function addDomain($db, string $domain, ?int $owner, string $type, string $slave_master, int|string $zone_template, array $groupIds = [], ?string $soaEditApi = null): ZoneWriteResult
    {
        // Last-resort guard: not every caller whitelists the kind, and an unknown
        // string would otherwise be written straight into the zone type.
        if (!in_array(strtoupper($type), ZoneType::getAllTypes(), true)) {
            return ZoneWriteResult::failure(_('Invalid or unexpected input given.'));
        }

        $zone_master_add = $this->userHasPermission('zone_master_add');
        $zone_slave_add = $this->userHasPermission('zone_slave_add');
        // Keeps the original string for MASTER/NATIVE zones, which pass '' here, and for
        // anything that fails validation - addDomain has never validated this argument.
        $slave_master = $this->normalizeMasterList($slave_master) ?? $slave_master;

        // TODO: make sure only one is possible if only one is enabled
        if ($zone_master_add || $zone_slave_add) {
            $dns_ns1 = $this->config->get('dns', 'ns1');
            $dns_hostmaster = $this->config->get('dns', 'hostmaster');
            $dns_ttl = $this->config->get('dns', 'ttl');

            // A replicating kind is inert without a primary, so require one rather
            // than letting the template slot alone satisfy the guard.
            $hasRequiredArgs = ZoneType::replicatesFromPrimary($type)
                ? (bool)($domain && $slave_master)
                : (bool)($domain && $zone_template);

            if ($hasRequiredArgs) {
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

                if (!ZoneType::replicatesFromPrimary($type)) {
                    $this->applySerialPolicy($domain_id, $domain, $soaEditApi);
                }

                $db->beginTransaction();
                try {
                    if ($this->backendProvider->isApiBackend()) {
                        // Zone ids come from the zones table here, so createZone() already
                        // inserted the row; fill in owner and template instead of duplicating it.
                        $stmt = $db->prepare("UPDATE zones SET owner = :owner, zone_templ_id = :zone_template WHERE domain_id = :domain_id");
                        $stmt->bindValue(':domain_id', $domain_id, PDO::PARAM_INT);
                        $stmt->bindValue(':owner', $owner, $owner !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
                        $stmt->bindValue(':zone_template', ($zone_template == "none") ? 0 : $zone_template, PDO::PARAM_INT);
                        $stmt->execute();

                        $zone_id = $domain_id;
                    } else {
                        $stmt = $db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (:domain_id, :owner, :zone_template)");
                        $stmt->bindValue(':domain_id', $domain_id, PDO::PARAM_INT);
                        $stmt->bindValue(':owner', $owner, $owner !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
                        $stmt->bindValue(':zone_template', ($zone_template == "none") ? 0 : $zone_template, PDO::PARAM_INT);
                        $stmt->execute();

                        // Pass the Postgres sequence name explicitly; MySQL/SQLite ignore it.
                        $zone_id = $db->lastInsertId('zones_id_seq');
                    }

                    // Ownerless zones keep their default empty account; no push needed on create
                    if ($owner !== null) {
                        $accountSync = new ZoneAccountSyncService($db, $this->config, $this->backendProvider);
                        $accountSync->syncZoneAccount($domain_id);
                    }

                    // Create sync tracking record if using a template
                    if ($zone_template != "none" && is_numeric($zone_template)) {
                        $syncService = new ZoneTemplateSyncService($db, $this->config, $this->backendProvider);
                        $syncService->createSyncRecord($zone_id, (int)$zone_template);
                        // Mark as synced since we're creating from template
                        $syncService->markZoneAsSynced($zone_id, (int)$zone_template);
                    }

                    // Assign group ownership within the same transaction
                    $uniqueGroupIds = array_unique($groupIds);
                    foreach ($uniqueGroupIds as $groupId) {
                        $stmt = $db->prepare("INSERT INTO zones_groups (domain_id, group_id, created_at) VALUES (:domain_id, :group_id, CURRENT_TIMESTAMP)");
                        $stmt->bindValue(':domain_id', $domain_id, PDO::PARAM_INT);
                        $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
                        $stmt->execute();
                    }

                    if (ZoneType::replicatesFromPrimary($type)) {
                        // Records arrive by transfer, so skip the apex SOA and any template
                        // records. Master IP is already set by backendProvider->createZone().
                        $db->commit();
                        $this->captureChange(function () use ($domain_id, $domain, $type, $slave_master, $owner): void {
                            $this->changeLogger->logZoneCreate([
                                'id' => $domain_id,
                                'name' => $domain,
                                'type' => $type,
                                'master' => $slave_master,
                                'owner' => $owner,
                            ]);
                        });
                        return ZoneWriteResult::ok((int)$domain_id);
                    } else {
                        if ($zone_template == "none" && $domain_id) {
                            $localTransaction = $this->backendProvider->supportsLocalWriteTransaction();
                            if (!$localTransaction) {
                                // The backend write cannot join this transaction, so land zones +
                                // zones_groups first; on SQLite the open lock would block PowerDNS.
                                $db->commit();
                            }

                            $ns1 = $dns_ns1;
                            $hm = $dns_hostmaster;
                            $ttl = $dns_ttl;

                            // Get SOA parameters from config
                            $soa_refresh = $this->config->get('dns', 'soa_refresh', 28800);
                            $soa_retry = $this->config->get('dns', 'soa_retry', 7200);
                            $soa_expire = $this->config->get('dns', 'soa_expire', 604800);
                            $soa_minimum = $this->config->get('dns', 'soa_minimum', 86400);

                            $serial = date("Ymd") . "00";

                            // Construct complete SOA record with all parameters
                            $soa_content = "$ns1 $hm $serial $soa_refresh $soa_retry $soa_expire $soa_minimum";

                            if (!$this->backendProvider->addRecord($domain_id, $domain, 'SOA', $soa_content, (int)$ttl, 0)) {
                                if ($localTransaction) {
                                    $db->rollBack();
                                }
                                $this->cleanupZoneOnFailure($domain_id, $domain);
                                $this->cleanupZoneMetadata($domain_id);
                                return ZoneWriteResult::backendFailure(_('Failed to create SOA record for zone.'));
                            }
                            if ($localTransaction) {
                                $db->commit();
                            }
                            $this->captureChange(function () use ($domain_id, $domain, $type, $owner): void {
                                $this->changeLogger->logZoneCreate([
                                    'id' => $domain_id,
                                    'name' => $domain,
                                    'type' => $type,
                                    'owner' => $owner,
                                ]);
                            });
                            return ZoneWriteResult::ok((int)$domain_id);
                        } elseif ($domain_id && is_numeric($zone_template)) {
                            $localTransaction = $this->backendProvider->supportsLocalWriteTransaction();
                            if (!$localTransaction) {
                                // The template records are written outside this transaction, so
                                // land zones + zones_groups before the first of them.
                                $db->commit();
                            }
                            $numericIds = $this->backendProvider->recordIdsAreNumeric();

                            $dns_ttl = $this->config->get('dns', 'ttl');

                            $templ_records = ZoneTemplate::getZoneTemplRecords($db, (int)$zone_template);
                            if (!empty($templ_records)) {
                                $zoneTemplate = new ZoneTemplate($this->db, $this->config, $this->backendProvider, $this->logger);
                                foreach ($templ_records as $r) {
                                    if (self::shouldApplyTemplateRecord($domain, $r["type"])) {
                                        $name = $zoneTemplate->parseTemplateValue($r["name"], $domain);
                                        $recordType = $r["type"];
                                        $content = $zoneTemplate->parseTemplateValue($r["content"], $domain, $recordType);
                                        $ttl = $r["ttl"];
                                        $prio = intval($r["prio"]);

                                        if (!$ttl) {
                                            $ttl = $dns_ttl;
                                        }

                                        $record_id = $this->backendProvider->addRecordGetId($domain_id, $name, $recordType, $content, (int)$ttl, $prio);
                                        if ($record_id === null) {
                                            if ($localTransaction) {
                                                $db->rollBack();
                                            }
                                            $this->cleanupZoneOnFailure($domain_id, $domain);
                                            $this->cleanupZoneMetadata($domain_id);
                                            return ZoneWriteResult::backendFailure(sprintf(_('Failed to create %s record for zone.'), $recordType));
                                        }

                                        // Link the record to the template so later template edits can
                                        // remove it precisely; encoded ids need the string-keyed table.
                                        if ($numericIds) {
                                            $stmt = $db->prepare("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (:domain_id, :record_id, :zone_templ_id)");
                                            $stmt->execute([
                                                ':domain_id' => $domain_id,
                                                ':record_id' => $record_id,
                                                ':zone_templ_id' => $r['zone_templ_id']
                                            ]);
                                        } else {
                                            $stmt = $db->prepare("INSERT INTO records_zone_templ_api (domain_id, record_id, zone_templ_id) VALUES (:domain_id, :record_id, :zone_templ_id)");
                                            $stmt->bindValue(':domain_id', $domain_id, PDO::PARAM_INT);
                                            $stmt->bindValue(':record_id', (string) $record_id, PDO::PARAM_STR);
                                            $stmt->bindValue(':zone_templ_id', (int) $r['zone_templ_id'], PDO::PARAM_INT);
                                            $stmt->execute();
                                        }
                                    }
                                }
                            }
                            if ($localTransaction) {
                                $db->commit();
                            }
                            $this->captureChange(function () use ($domain_id, $domain, $type, $zone_template, $owner): void {
                                $this->changeLogger->logZoneCreate([
                                    'id' => $domain_id,
                                    'name' => $domain,
                                    'type' => $type,
                                    'template_id' => (int) $zone_template,
                                    'owner' => $owner,
                                ]);
                            });
                            return ZoneWriteResult::ok((int)$domain_id);
                        } else {
                            $db->rollBack();
                            return ZoneWriteResult::backendFailure(sprintf(_('Invalid argument(s) given to function %s %s'), "addDomain", "could not create zone"));
                        }
                    }
                } catch (\Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $this->cleanupZoneOnFailure($domain_id, $domain);
                    // Removes whatever was committed before the failure; a no-op after a rollback.
                    $this->cleanupZoneMetadata($domain_id);
                    return ZoneWriteResult::backendFailure(sprintf(_('Failed to create zone: %s'), $e->getMessage()));
                }
            } else {
                return ZoneWriteResult::failure(sprintf(_('Invalid argument(s) given to function %s'), "addDomain"));
            }
        } else {
            return ZoneWriteResult::forbidden(_("You do not have the permission to add a master zone."));
        }
    }

    /**
     * Deletes a domain by a given id
     *
     * @param int $id Zone ID
     */
    public function deleteDomain(int $id): ZoneWriteResult
    {
        $perm_delete = Permission::getDeletePermission($this->db, $this->config);
        $user_is_zone_owner = $this->currentUserOwnsZone($id);

        if (ZoneAccessPolicy::levelAppliesToZone($perm_delete, $user_is_zone_owner)) {
            // Get zone name for backend deletion.
            $zoneName = $this->domainRepository->getDomainNameById($id);

            // Snapshot zone metadata + record count BEFORE deletion for the audit log.
            $zoneSnapshot = $this->snapshotZoneForLog($id, $zoneName);
            $recordCountBefore = $this->countRecordsForZone($id);

            if ($zoneName !== null) {
                // Delete DNS data via backend first (SQL or API).
                // This must happen before local cleanup so that a failure
                // does not leave Poweradmin metadata deleted while DNS zone remains.
                if (!$this->backendProvider->deleteZone($id, $zoneName)) {
                    return ZoneWriteResult::backendFailure(_('Failed to delete zone from DNS backend.'));
                }
            } else {
                // The name is gone (out-of-band delete or partial failure); the SQL backend
                // still clears records, metadata and keys by id, the API backend declines.
                $this->backendProvider->deleteZone($id, '');
            }

            // Clean up Poweradmin-internal tables in a transaction
            try {
                $this->db->beginTransaction();

                // Get zone_id before deleting zones record for sync cleanup
                $stmt = $this->db->prepare("SELECT id FROM zones WHERE domain_id = :id");
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                $zoneId = $stmt->fetchColumn();

                // Clean up zone template sync records if zone exists
                if ($zoneId) {
                    $syncService = new ZoneTemplateSyncService($this->db, $this->config, $this->backendProvider);
                    $syncService->cleanupZoneSyncRecords($zoneId);
                }

                $stmt = $this->db->prepare("DELETE FROM zones WHERE domain_id = :id");
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();

                $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :id");
                $stmt->execute([':id' => $id]);

                $stmt = $this->db->prepare("DELETE FROM records_zone_templ WHERE domain_id = :id");
                $stmt->execute([':id' => $id]);

                $stmt = $this->db->prepare("DELETE FROM records_zone_templ_api WHERE domain_id = :id");
                $stmt->execute([':id' => $id]);

                $this->db->commit();
            } catch (\Exception $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return ZoneWriteResult::backendFailure(sprintf(_('Failed to clean up zone metadata: %s'), $e->getMessage()));
            }

            $this->captureChange(function () use ($zoneSnapshot, $recordCountBefore): void {
                $this->changeLogger->logZoneDelete($zoneSnapshot, $recordCountBefore);
            });

            return ZoneWriteResult::ok($id);
        } else {
            return ZoneWriteResult::forbidden(_("You do not have the permission to delete a zone."));
        }
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
            $db = $this->db;
            $db->prepare("DELETE FROM records_zone_templ WHERE domain_id = :did")->execute([':did' => $domainId]);
            $db->prepare("DELETE FROM records_zone_templ_api WHERE domain_id = :did")->execute([':did' => $domainId]);
            $db->prepare("DELETE FROM zones_groups WHERE domain_id = :did")->execute([':did' => $domainId]);
            $zonesDeleteStmt = $db->prepare("DELETE FROM zones WHERE domain_id = :did");
            $zonesDeleteStmt->bindValue(':did', $domainId, PDO::PARAM_INT);
            $zonesDeleteStmt->execute();
        } catch (\Exception $e) {
            $this->logger->error('Failed to clean up zone metadata for domain_id {domainId}: {error}', ['domainId' => $domainId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Capture a minimal zone snapshot suitable for the change log.
     * Tolerates missing data (out-of-band deletes leave $zoneName null).
     */
    private function snapshotZoneForLog(int $domainId, ?string $zoneName): array
    {
        $type = null;
        try {
            $type = $this->domainRepository->getDomainType($domainId);
        } catch (Throwable $e) {
            // Type lookup is best-effort; the log still gets a row with null type.
        }

        return [
            'id' => $domainId,
            'name' => $zoneName,
            'type' => $type,
        ];
    }

    /**
     * Count records in a zone before deletion. Used to summarize how many
     * records were removed by a zone delete in the audit log.
     */
    private function countRecordsForZone(int $domainId): int
    {
        try {
            $tableNameService = new TableNameService($this->config);
            $records_table = $tableNameService->getTable(PdnsTable::RECORDS);
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM $records_table WHERE domain_id = :did");
            $stmt->bindValue(':did', $domainId, PDO::PARAM_INT);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function userCanEditZoneMetadata(int $zoneId): bool
    {
        if ($this->userHasPermission('zone_meta_edit_others')) {
            return true;
        }
        if (
            $this->userHasPermission('zone_meta_edit_own')
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
        if ($this->userRepository()->getUserById($user_id) === null) {
            return ZoneWriteResult::failure(sprintf(_('Invalid argument(s) given to function %s %s'), "addOwnerToZone", "$zone_id / $user_id"));
        }

        $zoneRepository = (new RepositoryFactory($this->db, $this->config, $this->backendProvider))->createZoneRepository();
        if (!$zoneRepository->isUserZoneOwner($zone_id, $user_id) && !$zoneRepository->addOwnerToZone($zone_id, $user_id)) {
            return ZoneWriteResult::backendFailure(_('Failed to add the owner to the zone.'));
        }

        return ZoneWriteResult::ok($zone_id);
    }

    /**
     * Get Zone Template ID for Zone ID
     *
     * @param object $db Database connection
     * @param int $zone_id Zone ID
     *
     * @return int Zone Template ID (0 if no template or zone not found)
     */
    public static function getZoneTemplate($db, int $zone_id): int
    {
        $stmt = $db->prepare("SELECT zone_templ_id FROM zones WHERE domain_id = :zone_id");
        $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchColumn();

        // Handle NULL (PostgreSQL) or false (no row found)
        if ($result === null || $result === false) {
            return 0;
        }

        return (int) $result;
    }

    /**
     * Update All Zone Records for Zone ID with Zone Template
     *
     * @param string $db_type Database type
     * @param int $dns_ttl Default TTL
     * @param int $zone_id Zone ID to update
     * @param int $zone_template_id Zone Template ID to use for update
     */
    public function updateZoneRecords(string $db_type, int $dns_ttl, int $zone_id, int $zone_template_id): ZoneWriteResult
    {
        // Secondary and Consumer zones replicate from a primary - applying a
        // template would write replicated records, so skip them entirely
        if (ZoneType::isReadOnly($this->domainRepository->getDomainType($zone_id))) {
            return ZoneWriteResult::ok($zone_id);
        }

        // Without content-edit rights the previous template's records stay, so the
        // caller must not report success over a zone holding records from both templates.
        $canRemoveOldTemplateRecords = $zone_template_id == 0
            || ZoneAccessPolicy::levelAppliesToZone(Permission::getEditPermission($this->db, $this->config), $this->currentUserOwnsZone($zone_id));

        $zone_master_add = $this->userHasPermission('zone_master_add');
        $zone_slave_add = $this->userHasPermission('zone_slave_add');

        $soa_rec = $this->soaRecordManager->getSOARecord($zone_id);

        $localTransaction = $this->backendProvider->supportsLocalWriteTransaction();
        $numericIds = $this->backendProvider->recordIdsAreNumeric();

        $tableNameService = new TableNameService($this->config);
        $records_table = $tableNameService->getTable(PdnsTable::RECORDS);

        $this->db->beginTransaction();
        try {
            if ($zone_template_id != 0) {
                if ($canRemoveOldTemplateRecords) {
                    if (!$numericIds) {
                        // Encoded record ids live in the string-keyed records_zone_templ_api
                        // table, so only the records this template applied are removed.
                        $this->deleteTemplateRecordsViaApi($zone_id, $zone_template_id, $dns_ttl);
                    } else {
                        // Snapshot template-linked records before the bulk delete
                        // so the audit log captures every removal.
                        $selectStmt = $this->db->prepare(
                            "SELECT r.id, r.name, r.type, r.content, r.ttl, r.prio, r.disabled
                             FROM $records_table r
                             INNER JOIN records_zone_templ rzt ON r.id = rzt.record_id
                             WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id"
                        );
                        $selectStmt->execute([':zone_id' => $zone_id, ':zone_template_id' => $zone_template_id]);
                        $templateRecordsRemoved = $selectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                        // Delete the template-applied records. Only MySQL can drop the
                        // record and its mapping in one statement; the other backends
                        // delete records here and the mapping is cleared uniformly below.
                        if ($db_type == 'pgsql') {
                            $query = "DELETE FROM $records_table r USING records_zone_templ rzt WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id AND r.id = rzt.record_id";
                        } elseif ($db_type == 'sqlite') {
                            $query = "DELETE FROM $records_table WHERE id IN (SELECT r.id FROM $records_table r LEFT JOIN records_zone_templ rzt ON r.id = rzt.record_id WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id)";
                        } else {
                            $query = "DELETE r FROM $records_table r LEFT JOIN records_zone_templ rzt ON r.id = rzt.record_id WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id";
                        }
                        $stmt = $this->db->prepare($query);
                        $stmt->execute(array(':zone_id' => $zone_id, ':zone_template_id' => $zone_template_id));

                        // Clear the template->record mapping for every backend. Otherwise
                        // pgsql/sqlite leave orphaned rows behind, and on SQLite a reused
                        // rowid could later resolve a stale mapping to an unrelated record.
                        $mappingStmt = $this->db->prepare("DELETE FROM records_zone_templ WHERE domain_id = :zone_id AND zone_templ_id = :zone_template_id");
                        $mappingStmt->execute([':zone_id' => $zone_id, ':zone_template_id' => $zone_template_id]);

                        if ($templateRecordsRemoved !== []) {
                            $this->captureChange(function () use ($templateRecordsRemoved, $zone_id): void {
                                foreach ($templateRecordsRemoved as $removed) {
                                    $this->changeLogger->logRecordDelete($removed, $zone_id);
                                }
                            });
                        }
                    }
                }

                // Use the permissions we already checked earlier
                if ($zone_master_add || $zone_slave_add) {
                    $domain = $this->domainRepository->getDomainNameById($zone_id);

                    // Get all records from the template
                    $templ_records = ZoneTemplate::getZoneTemplRecords($this->db, $zone_template_id);
                    $zoneTemplate = new ZoneTemplate($this->db, $this->config, $this->backendProvider, $this->logger);

                    // Writes outside this transaction would not see the rows above until it commits
                    if (!$localTransaction) {
                        $this->db->commit();
                    }

                    // Process each template record
                    foreach ($templ_records as $r) {
                        if (self::shouldApplyTemplateRecord($domain, $r["type"])) {
                            $name = $zoneTemplate->parseTemplateValue($r["name"], $domain);
                            $recordType = $r["type"];

                            if ($recordType == "SOA") {
                                if ($this->backendProvider->isApiBackend()) {
                                    // PowerDNS manages the SOA of API-created zones; skip SOA template records
                                    continue;
                                }
                                // For SOA records, delete existing ones and use updated SOA record
                                $soaSelect = $this->db->prepare("SELECT id, name, type, content, ttl, prio, disabled FROM $records_table WHERE domain_id = :zone_id AND type = 'SOA'");
                                $soaSelect->execute([':zone_id' => $zone_id]);
                                $existingSoaRecords = $soaSelect->fetchAll(PDO::FETCH_ASSOC) ?: [];

                                $stmt = $this->db->prepare("DELETE FROM $records_table WHERE domain_id = :zone_id AND type = 'SOA'");
                                $stmt->execute([':zone_id' => $zone_id]);

                                if ($existingSoaRecords !== []) {
                                    $this->captureChange(function () use ($existingSoaRecords, $zone_id): void {
                                        foreach ($existingSoaRecords as $soaRecord) {
                                            $this->changeLogger->logRecordDelete($soaRecord, $zone_id);
                                        }
                                    });
                                }

                                $content = $this->soaRecordManager->getUpdatedSOARecord($soa_rec);
                                if ($content == "") {
                                    $content = $zoneTemplate->parseTemplateValue($r["content"], $domain, $recordType);
                                }
                            } else {
                                $content = $zoneTemplate->parseTemplateValue($r["content"], $domain, $recordType);
                            }

                            $ttl = $r["ttl"];
                            $prio = intval($r["prio"]);

                            if (!$ttl) {
                                $ttl = $dns_ttl;
                            }

                            // Only insert if the record doesn't already exist
                            if (!$this->backendProvider->recordExists($zone_id, $name, $recordType, $content)) {
                                $record_id = $this->backendProvider->addRecordGetId($zone_id, $name, $recordType, $content, (int)$ttl, $prio);
                                if ($record_id === null) {
                                    continue;
                                }

                                // Link the record to the template so later template edits can
                                // remove it precisely; encoded ids need the string-keyed table.
                                if ($numericIds) {
                                    $stmt = $this->db->prepare("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (:zone_id, :record_id, :zone_template_id)");
                                    $stmt->execute([
                                        ':zone_id' => $zone_id,
                                        ':record_id' => $record_id,
                                        ':zone_template_id' => $zone_template_id
                                    ]);
                                } else {
                                    $stmt = $this->db->prepare("INSERT INTO records_zone_templ_api (domain_id, record_id, zone_templ_id) VALUES (:zone_id, :record_id, :zone_template_id)");
                                    $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
                                    $stmt->bindValue(':record_id', (string) $record_id, PDO::PARAM_STR);
                                    $stmt->bindValue(':zone_template_id', $zone_template_id, PDO::PARAM_INT);
                                    $stmt->execute();
                                }

                                $this->captureChange(function () use ($record_id, $name, $recordType, $content, $ttl, $prio, $zone_id): void {
                                    $this->changeLogger->logRecordCreate([
                                        'id' => $record_id,
                                        'name' => $name,
                                        'type' => $recordType,
                                        'content' => $content,
                                        'ttl' => (int) $ttl,
                                        'prio' => $prio,
                                    ], $zone_id);
                                });
                            }
                        }
                    }
                }
            }

            // Update the zone's template ID
            $stmt = $this->db->prepare("UPDATE zones
                    SET zone_templ_id = :zone_template_id
                    WHERE domain_id = :zone_id");
            $stmt->bindValue(':zone_template_id', $zone_template_id, PDO::PARAM_INT);
            $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
            $stmt->execute();

            // Reconcile zone_template_sync so stale rows for a previous template don't
            // keep showing the zone as out-of-sync after it has been reassigned. A zone
            // shared by several owners has one row per owner, so handle every one.
            $zonesIdStmt = $this->db->prepare("SELECT id FROM zones WHERE domain_id = :domain_id");
            $zonesIdStmt->bindValue(':domain_id', $zone_id, PDO::PARAM_INT);
            $zonesIdStmt->execute();
            $zonesIds = $zonesIdStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($zonesIds) {
                $syncService = new ZoneTemplateSyncService($this->db, $this->config, $this->backendProvider);
                foreach ($zonesIds as $zonesId) {
                    $syncService->removeStaleSyncRecords((int)$zonesId, $zone_template_id);
                    if ($zone_template_id !== 0) {
                        $syncService->createSyncRecord((int)$zonesId, $zone_template_id);
                        $syncService->markZoneAsSynced((int)$zonesId, $zone_template_id);
                    }
                }
            }

            if ($this->db->inTransaction()) {
                $this->db->commit();
            }

            return $canRemoveOldTemplateRecords
                ? ZoneWriteResult::ok($zone_id)
                : ZoneWriteResult::forbidden(_('You do not have permission to edit this zone.'));
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ZoneWriteResult::backendFailure(sprintf(_('Failed to update zone records: %s'), $e->getMessage()));
        }
    }

    /**
     * Delete records that this template applied to a zone, via the API backend.
     *
     * Looks up the encoded RecordIdentifier values stored in records_zone_templ_api
     * for the given (zone, template) pair and deletes only those records, leaving
     * any user-authored entries that happen to share name/type/content untouched.
     *
     * Pre-existing API zones from before records_zone_templ_api was introduced
     * have no mapping rows, so their template records are left in place rather
     * than being matched fuzzily; the operator removes them by hand.
     */
    private function deleteTemplateRecordsViaApi(int $zone_id, int $zone_template_id, int $dns_ttl): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, record_id
             FROM records_zone_templ_api
             WHERE domain_id = :zone_id AND zone_templ_id = :zone_template_id"
        );
        $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
        $stmt->bindValue(':zone_template_id', $zone_template_id, PDO::PARAM_INT);
        $stmt->execute();
        $mappingRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($mappingRows === []) {
            return;
        }

        // Index zone records by encoded ID so the audit log can capture full
        // before-state for each delete. Records that no longer exist in the
        // backend (e.g. removed out-of-band) are still removed from the mapping
        // below, but skip the deleteRecord/log call for them.
        $recordsByEncodedId = [];
        foreach ($this->backendProvider->getRecordsByZoneId($zone_id) as $record) {
            if (isset($record['id'])) {
                $recordsByEncodedId[(string) $record['id']] = $record;
            }
        }

        $deletedMappingIds = [];
        foreach ($mappingRows as $mapping) {
            $encodedId = (string) $mapping['record_id'];
            $record = $recordsByEncodedId[$encodedId] ?? null;

            if ($record !== null && $this->backendProvider->deleteRecord($encodedId)) {
                $this->captureChange(function () use ($record, $zone_id): void {
                    $this->changeLogger->logRecordDelete([
                        'id' => $record['id'] ?? null,
                        'name' => $record['name'] ?? null,
                        'type' => $record['type'] ?? null,
                        'content' => $record['content'] ?? null,
                        'ttl' => isset($record['ttl']) ? (int) $record['ttl'] : null,
                        'prio' => isset($record['prio']) ? (int) $record['prio'] : null,
                        'disabled' => $record['disabled'] ?? null,
                    ], $zone_id);
                });
            }
            $deletedMappingIds[] = (int) $mapping['id'];
        }

        $placeholders = implode(',', array_fill(0, count($deletedMappingIds), '?'));
        $cleanup = $this->db->prepare("DELETE FROM records_zone_templ_api WHERE id IN ($placeholders)");
        foreach ($deletedMappingIds as $i => $id) {
            $cleanup->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $cleanup->execute();
    }

    /**
     * Decide whether a template record should be inserted into the target zone.
     *
     * Historically IPv4 reverse zones (in-addr.arpa) were limited to NS/SOA because
     * early templates auto-populated A records for webip/mailip. The allowlist now
     * also covers PTR, LUA, CNAME and TXT so legitimate reverse-zone records (e.g.
     * LUA-driven dynamic PTR generation, RFC 2317 classless delegations) are no
     * longer silently dropped. IPv6 reverse zones (ip6.arpa) carry no restriction.
     */
    private const IPV4_REVERSE_TEMPLATE_TYPES = ['NS', 'SOA', 'PTR', 'LUA', 'CNAME', 'TXT'];

    private static function shouldApplyTemplateRecord(string $domain, string $type): bool
    {
        if (!self::isIpv4ReverseZone($domain)) {
            return true;
        }
        return in_array($type, self::IPV4_REVERSE_TEMPLATE_TYPES, true);
    }

    private static function isIpv4ReverseZone(string $domain): bool
    {
        return stripos($domain, 'in-addr.arpa') !== false;
    }
}
