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
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Error\RecordIdNotFoundException;
use Poweradmin\Domain\Error\ZoneIdNotFoundException;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\ZoneTemplateSyncService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Service class for managing domains/zones
 */
class DomainManager implements DomainManagerInterface
{
    private PDO $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private SOARecordManagerInterface $soaRecordManager;
    private DomainRepositoryInterface $domainRepository;
    private IPAddressValidator $ipAddressValidator;
    private DnsBackendProvider $backendProvider;
    private LoggerInterface $logger;
    private RecordChangeLogger $changeLogger;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param ConfigurationManager $config Configuration manager
     * @param SOARecordManagerInterface $soaRecordManager SOA record manager
     * @param DomainRepositoryInterface $domainRepository Domain repository
     * @param DnsBackendProvider|null $backendProvider DNS backend provider (auto-created if null)
     */
    public function __construct(
        PDO $db,
        ConfigurationManager $config,
        SOARecordManagerInterface $soaRecordManager,
        DomainRepositoryInterface $domainRepository,
        ?DnsBackendProvider $backendProvider = null,
        ?LoggerInterface $logger = null,
        ?RecordChangeLogger $changeLogger = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->soaRecordManager = $soaRecordManager;
        $this->domainRepository = $domainRepository;
        $this->ipAddressValidator = new IPAddressValidator();
        $this->backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        $this->logger = $logger ?? new NullLogger();
        $this->changeLogger = $changeLogger ?? new RecordChangeLogger($db);
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
            'name' => $zone['name'] ?? null,
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
     * @param string $type Type of domain ['NATIVE','MASTER','SLAVE']
     * @param string $slave_master Master server hostname for domain
     * @param int|string $zone_template ID of zone template ['none' or int]
     *
     * @return boolean true on success
     */
    public function addDomain($db, string $domain, ?int $owner, string $type, string $slave_master, int|string $zone_template, array $groupIds = []): bool
    {
        $zone_master_add = UserManager::verifyPermission($db, 'zone_master_add');
        $zone_slave_add = UserManager::verifyPermission($db, 'zone_slave_add');

        // TODO: make sure only one is possible if only one is enabled
        if ($zone_master_add || $zone_slave_add) {
            $dns_ns1 = $this->config->get('dns', 'ns1');
            $dns_hostmaster = $this->config->get('dns', 'hostmaster');
            $dns_ttl = $this->config->get('dns', 'ttl');

            if (
                ($domain && $zone_template) ||
                (preg_match('/in-addr.arpa/i', $domain) && $zone_template) ||
                ($type == "SLAVE" && $domain && $slave_master)
            ) {
                // Create zone BEFORE starting the transaction. In API mode,
                // createZone() polls the DB to discover the new domain ID.
                // If called inside a transaction, snapshot isolation can hide
                // the row that PowerDNS wrote on a different connection.
                try {
                    $domain_id = $this->backendProvider->createZone($domain, $type, $slave_master);
                } catch (ZoneIdNotFoundException $e) {
                    // Zone was created via API but DB ID lookup timed out.
                    // Clean up the orphaned zone to avoid unmanaged state.
                    $this->cleanupZoneOnFailure(0, $domain);
                    $this->messageService->addSystemError(_('Failed to create zone in DNS backend.'));
                    return false;
                } catch (\Exception $e) {
                    $this->messageService->addSystemError(sprintf(_('Failed to create zone: %s'), $e->getMessage()));
                    return false;
                }
                if ($domain_id === false) {
                    // API call was rejected (duplicate zone, validation error, etc.)
                    // Zone was NOT created - do not attempt cleanup as it could
                    // delete an existing zone with the same name.
                    $this->messageService->addSystemError(_('Failed to create zone in DNS backend.'));
                    return false;
                }

                $db->beginTransaction();
                try {
                    if ($this->backendProvider->isApiBackend()) {
                        // In API mode, createZone() already inserted the zones row.
                        // Update it with owner and template info instead of creating a duplicate.
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

                        $zone_id = $db->lastInsertId();
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

                    if ($type == "SLAVE") {
                        // Master IP is already set by backendProvider->createZone()
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
                        return true;
                    } else {
                        if ($zone_template == "none" && $domain_id) {
                            $isApiBackend = $this->backendProvider->isApiBackend();
                            if ($isApiBackend) {
                                // Commit zones + zones_groups before SOA update.
                                // addRecord() calls the PowerDNS API which writes
                                // to the same DB. With SQLite, holding a write lock
                                // here would deadlock the PowerDNS write.
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
                                if (!$isApiBackend) {
                                    $db->rollBack();
                                }
                                $this->cleanupZoneOnFailure($domain_id, $domain);
                                if ($isApiBackend) {
                                    $this->cleanupZoneMetadata($domain_id);
                                }
                                $this->messageService->addSystemError(_('Failed to create SOA record for zone.'));
                                return false;
                            }
                            if (!$isApiBackend) {
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
                            return true;
                        } elseif ($domain_id && is_numeric($zone_template)) {
                            $isApiBackend = $this->backendProvider->isApiBackend();
                            if ($isApiBackend) {
                                // Commit zones + zones_groups before template records.
                                // addRecordGetId() polls the DB for records that PowerDNS
                                // writes via a separate connection. Snapshot isolation
                                // inside this transaction would hide those rows.
                                $db->commit();
                            }

                            $dns_ttl = $this->config->get('dns', 'ttl');

                            $templ_records = ZoneTemplate::getZoneTemplRecords($db, $zone_template);
                            if (!empty($templ_records)) {
                                // Process the template records
                                foreach ($templ_records as $r) {
                                    if ((preg_match('/in-addr.arpa/i', $domain) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!preg_match('/in-addr.arpa/i', $domain))) {
                                        $zoneTemplate = new ZoneTemplate($this->db, $this->config);
                                        $name = $zoneTemplate->parseTemplateValue($r["name"], $domain);
                                        $recordType = $r["type"];
                                        $content = $zoneTemplate->parseTemplateValue($r["content"], $domain);
                                        $ttl = $r["ttl"];
                                        $prio = intval($r["prio"]);

                                        if (!$ttl) {
                                            $ttl = $dns_ttl;
                                        }

                                        try {
                                            $record_id = $this->backendProvider->addRecordGetId($domain_id, $name, $recordType, $content, (int)$ttl, $prio);
                                        } catch (RecordIdNotFoundException $e) {
                                            // Record was created via API but DB ID not found.
                                            // Skip template linkage for this record to avoid
                                            // storing a synthetic ID that breaks cleanup JOINs.
                                            $this->logger->error('Failed to get record ID after API creation: {error}', ['error' => $e->getMessage()]);
                                            continue;
                                        }
                                        if ($record_id === null && $isApiBackend) {
                                            $this->cleanupZoneOnFailure($domain_id, $domain);
                                            $this->cleanupZoneMetadata($domain_id);
                                            $this->messageService->addSystemError(sprintf(_('Failed to create %s record for zone.'), $recordType));
                                            return false;
                                        }
                                        if ($record_id === null) {
                                            $record_id = 0;
                                        }

                                        // Link the record to the template so future template
                                        // edits can remove it precisely. SQL records use
                                        // INT-keyed records_zone_templ; API records use
                                        // string-keyed records_zone_templ_api.
                                        if ($isApiBackend) {
                                            $stmt = $db->prepare("INSERT INTO records_zone_templ_api (domain_id, record_id, zone_templ_id) VALUES (:domain_id, :record_id, :zone_templ_id)");
                                            $stmt->bindValue(':domain_id', $domain_id, PDO::PARAM_INT);
                                            $stmt->bindValue(':record_id', (string) $record_id, PDO::PARAM_STR);
                                            $stmt->bindValue(':zone_templ_id', (int) $r['zone_templ_id'], PDO::PARAM_INT);
                                            $stmt->execute();
                                        } else {
                                            $stmt = $db->prepare("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (:domain_id, :record_id, :zone_templ_id)");
                                            $stmt->execute([
                                                ':domain_id' => $domain_id,
                                                ':record_id' => $record_id,
                                                ':zone_templ_id' => $r['zone_templ_id']
                                            ]);
                                        }
                                    }
                                }
                            }
                            if (!$isApiBackend) {
                                $db->commit();
                            }
                            $this->captureChange(function () use ($domain_id, $domain, $type, $zone_template, $owner): void {
                                $this->changeLogger->logZoneCreate([
                                    'id' => $domain_id,
                                    'name' => $domain,
                                    'type' => $type,
                                    'template_id' => is_numeric($zone_template) ? (int) $zone_template : null,
                                    'owner' => $owner,
                                ]);
                            });
                            return true;
                        } else {
                            $db->rollBack();
                            $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "addDomain", "could not create zone"));
                            return false;
                        }
                    }
                } catch (\Exception $e) {
                    $wasInTransaction = $db->inTransaction();
                    if ($wasInTransaction) {
                        $db->rollBack();
                    }
                    $this->cleanupZoneOnFailure($domain_id, $domain);
                    // In API mode, the zones row is inserted before beginTransaction(),
                    // so rollBack() won't remove it. Always clean up metadata in that case.
                    if (!$wasInTransaction || $this->backendProvider->isApiBackend()) {
                        $this->cleanupZoneMetadata($domain_id);
                    }
                    $this->messageService->addSystemError(sprintf(_('Failed to create zone: %s'), $e->getMessage()));
                    return false;
                }
            } else {
                $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s'), "addDomain"));
                return false;
            }
        } else {
            $this->messageService->addSystemError(_("You do not have the permission to add a master zone."));
            return false;
        }
    }

    /**
     * Deletes a domain by a given id
     *
     * @param int $id Zone ID
     *
     * @return boolean true on success
     */
    public function deleteDomain(int $id): bool
    {
        $perm_edit = Permission::getEditPermission($this->db);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $id);

        if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
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
                    $this->messageService->addSystemError(_('Failed to delete zone from DNS backend.'));
                    return false;
                }
            } elseif (!$this->backendProvider->isApiBackend()) {
                // Domain name row is gone (out-of-band delete or partial failure)
                // but the SQL backend can still clean up records, domainmetadata,
                // and cryptokeys by domain ID alone.
                $this->backendProvider->deleteZone($id, '');
            }
            // For API backend with missing domain name: skip backend call
            // (API requires the zone name) and proceed to metadata cleanup.

            // Clean up Poweradmin-internal tables in a transaction
            try {
                $this->db->beginTransaction();

                // Get zone_id before deleting zones record for sync cleanup
                $stmt = $this->db->prepare("SELECT id FROM zones WHERE domain_id = :id");
                $stmt->execute([':id' => $id]);
                $zoneId = $stmt->fetchColumn();

                // Clean up zone template sync records if zone exists
                if ($zoneId) {
                    $syncService = new ZoneTemplateSyncService($this->db, $this->config, $this->backendProvider);
                    $syncService->cleanupZoneSyncRecords($zoneId);
                }

                $stmt = $this->db->prepare("DELETE FROM zones WHERE domain_id = :id");
                $stmt->execute([':id' => $id]);

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
                $this->messageService->addSystemError(sprintf(_('Failed to clean up zone metadata: %s'), $e->getMessage()));
                return false;
            }

            $this->captureChange(function () use ($zoneSnapshot, $recordCountBefore): void {
                $this->changeLogger->logZoneDelete($zoneSnapshot, $recordCountBefore);
            });

            return true;
        } else {
            $this->messageService->addSystemError(_("You do not have the permission to delete a zone."));
            return false;
        }
    }

    /**
     * Delete array of domains
     *
     * @param int[] $domains Array of Domain IDs to delete
     *
     * @return boolean true on success
     */
    public function deleteDomains(array $domains): bool
    {
        $allSucceeded = true;

        foreach ($domains as $id) {
            $perm_edit = Permission::getEditPermission($this->db);
            $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $id);

            if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
                // Get zone name for backend deletion.
                $zoneName = $this->domainRepository->getDomainNameById($id);

                // Snapshot for audit log
                $zoneSnapshot = $this->snapshotZoneForLog($id, $zoneName);
                $recordCountBefore = $this->countRecordsForZone($id);

                if ($zoneName !== null) {
                    // Delete DNS data BEFORE the transaction. API deletions are
                    // irreversible, so they must not be inside a transaction that
                    // could roll back and leave metadata pointing to a deleted zone.
                    if (!$this->backendProvider->deleteZone($id, $zoneName)) {
                        $this->messageService->addSystemError(_('Failed to delete zone from DNS backend.'));
                        $allSucceeded = false;
                        continue;
                    }
                } elseif (!$this->backendProvider->isApiBackend()) {
                    // Domain name row is gone but SQL backend can still
                    // clean up by domain ID.
                    $this->backendProvider->deleteZone($id, '');
                }

                // Clean up Poweradmin metadata in a transaction
                $this->db->beginTransaction();
                try {
                    // Get zone_id before deleting zones record for sync cleanup
                    $stmt = $this->db->prepare("SELECT id FROM zones WHERE domain_id = :id");
                    $stmt->execute([':id' => $id]);
                    $zoneId = $stmt->fetchColumn();

                    // Clean up zone template sync records if zone exists
                    if ($zoneId) {
                        $syncService = new ZoneTemplateSyncService($this->db, $this->config, $this->backendProvider);
                        $syncService->cleanupZoneSyncRecords($zoneId);
                    }

                    // Clean up Poweradmin-internal tables (always SQL)
                    $stmt = $this->db->prepare("DELETE FROM zones WHERE domain_id = :id");
                    $stmt->execute([':id' => $id]);

                    $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :id");
                    $stmt->execute([':id' => $id]);

                    $stmt = $this->db->prepare("DELETE FROM records_zone_templ WHERE domain_id = :id");
                    $stmt->execute([':id' => $id]);

                    $stmt = $this->db->prepare("DELETE FROM records_zone_templ_api WHERE domain_id = :id");
                    $stmt->execute([':id' => $id]);

                    $this->db->commit();

                    $this->captureChange(function () use ($zoneSnapshot, $recordCountBefore): void {
                        $this->changeLogger->logZoneDelete($zoneSnapshot, $recordCountBefore);
                    });
                } catch (\Exception $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $this->messageService->addSystemError(sprintf(_('Failed to delete zone metadata: %s'), $e->getMessage()));
                    $allSucceeded = false;
                }
            } else {
                $this->messageService->addSystemError(_("You do not have the permission to delete a zone."));
                $allSucceeded = false;
            }
        }

        return $allSucceeded;
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
            $db->prepare("DELETE FROM zones WHERE domain_id = :did")->execute([':did' => $domainId]);
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

    /**
     * Change Zone Type
     *
     * @param string $type New Zone Type [NATIVE,MASTER,SLAVE]
     * @param int $id Zone ID
     */
    public function changeZoneType(string $type, int $id): bool
    {
        $beforeZone = $this->snapshotZoneMetadataForLog($id);

        if (!$this->backendProvider->updateZoneType($id, $type)) {
            $this->messageService->addSystemError(_('Failed to update zone type in DNS backend.'));
            return false;
        }

        if ($beforeZone !== null) {
            $afterZone = $this->snapshotZoneMetadataForLog($id) ?? array_merge($beforeZone, ['type' => $type]);
            $this->captureChange(function () use ($beforeZone, $afterZone): void {
                $this->changeLogger->logZoneMetadataEdit($beforeZone, $afterZone);
            });
        }

        return true;
    }

    /**
     * Change Slave Zone's Master IP Address
     *
     * @param int $zone_id Zone ID
     * @param string $ip_slave_master Master IP Address
     */
    public function changeZoneSlaveMaster(int $zone_id, string $ip_slave_master): bool
    {
        if (!$this->ipAddressValidator->areMultipleValidIPs($ip_slave_master)) {
            $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "changeZoneSlaveMaster", "This is not a valid IPv4 or IPv6 address: $ip_slave_master"));
            return false;
        }

        $beforeZone = $this->snapshotZoneMetadataForLog($zone_id);

        if (!$this->backendProvider->updateZoneMaster($zone_id, $ip_slave_master)) {
            $this->messageService->addSystemError(_('Failed to update zone master in DNS backend.'));
            return false;
        }

        if ($beforeZone !== null) {
            $afterZone = $this->snapshotZoneMetadataForLog($zone_id) ?? array_merge($beforeZone, ['master' => $ip_slave_master]);
            $this->captureChange(function () use ($beforeZone, $afterZone): void {
                $this->changeLogger->logZoneMetadataEdit($beforeZone, $afterZone);
            });
        }

        return true;
    }

    /**
     * Change owner of a domain
     *
     * @param int $zone_id Zone ID
     * @param int $user_id User ID
     *
     * @return boolean true when succesful
     */
    public static function addOwnerToZone($db, int $zone_id, int $user_id): bool
    {
        if (UserManager::verifyPermission($db, 'zone_meta_edit_others') || (UserManager::verifyPermission($db, 'zone_meta_edit_own') && UserManager::verifyUserIsOwnerZoneId($db, $zone_id))) {
            if (UserManager::isValidUser($db, $user_id)) {
                $stmt = $db->prepare("SELECT COUNT(id) FROM zones WHERE owner = ? AND domain_id = ?");
                $stmt->execute([$user_id, $zone_id]);
                if ($stmt->fetchColumn() == 0) {
                    $zone_templ_id = self::getZoneTemplate($db, $zone_id);
                    if ($zone_templ_id == null) {
                        $zone_templ_id = 0;
                    }
                    $stmt = $db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES(?, ?, ?)");
                    $stmt->execute([$zone_id, $user_id, $zone_templ_id]);
                    return true;
                } else {
                    $messageService = new MessageService();
                    $messageService->addSystemError(_('The selected user already owns the zone.'));
                    return false;
                }
            } else {
                $messageService = new MessageService();
                $messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "addOwnerToZone", "$zone_id / $user_id"));
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Delete owner from zone
     *
     * @param int $zone_id Zone ID
     * @param int $user_id User ID
     *
     * @return boolean true on success
     */
    public static function deleteOwnerFromZone($db, int $zone_id, int $user_id): bool
    {
        if (UserManager::verifyPermission($db, 'zone_meta_edit_others') || (UserManager::verifyPermission($db, 'zone_meta_edit_own') && UserManager::verifyUserIsOwnerZoneId($db, $zone_id))) {
            if (UserManager::isValidUser($db, $user_id)) {
                $stmt = $db->prepare("SELECT COUNT(id) FROM zones WHERE domain_id = ?");
                $stmt->execute([$zone_id]);
                if ($stmt->fetchColumn() > 1) {
                    $stmt = $db->prepare("DELETE FROM zones WHERE owner = ? AND domain_id = ?");
                    $stmt->execute([$user_id, $zone_id]);
                    return true;
                } else {
                    $messageService = new MessageService();
                    $messageService->addSystemError(_('There must be at least one owner for a zone.'));
                    return false;
                }
            } else {
                $messageService = new MessageService();
                $messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "deleteOwnerFromZone", "$zone_id / $user_id"));
                return false;
            }
        } else {
            return false;
        }
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
        $stmt->execute([':zone_id' => $zone_id]);
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
    public function updateZoneRecords(string $db_type, int $dns_ttl, int $zone_id, int $zone_template_id): void
    {
        $perm_edit = Permission::getEditPermission($this->db);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        $zone_master_add = UserManager::verifyPermission($this->db, 'zone_master_add');
        $zone_slave_add = UserManager::verifyPermission($this->db, 'zone_slave_add');

        $soa_rec = $this->soaRecordManager->getSOARecord($zone_id);

        $isApiBackend = $this->backendProvider->isApiBackend();

        $tableNameService = new TableNameService($this->config);
        $records_table = $tableNameService->getTable(PdnsTable::RECORDS);

        $this->db->beginTransaction();
        try {
            if ($zone_template_id != 0) {
                if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
                    if ($isApiBackend) {
                        // API mode: encoded RecordIdentifier IDs are kept in the
                        // string-keyed records_zone_templ_api table so we can
                        // remove only the records this template applied here.
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

                        // SQL mode: delete records and mapping in one query
                        if ($db_type == 'pgsql') {
                            $query = "DELETE FROM $records_table r USING records_zone_templ rzt WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id AND r.id = rzt.record_id";
                        } elseif ($db_type == 'sqlite') {
                            $query = "DELETE FROM $records_table WHERE id IN (SELECT r.id FROM $records_table r LEFT JOIN records_zone_templ rzt ON r.id = rzt.record_id WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id)";
                        } else {
                            $query = "DELETE r, rzt FROM $records_table r LEFT JOIN records_zone_templ rzt ON r.id = rzt.record_id WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id";
                        }
                        $stmt = $this->db->prepare($query);
                        $stmt->execute(array(':zone_id' => $zone_id, ':zone_template_id' => $zone_template_id));

                        if ($templateRecordsRemoved !== []) {
                            $this->captureChange(function () use ($templateRecordsRemoved, $zone_id): void {
                                foreach ($templateRecordsRemoved as $removed) {
                                    $this->changeLogger->logRecordDelete($removed, $zone_id);
                                }
                            });
                        }
                    }
                } else {
                    $this->messageService->addSystemError(_("You do not have the permission to delete a zone."));
                }

                // Use the permissions we already checked earlier
                if ($zone_master_add || $zone_slave_add) {
                    $domain = $this->domainRepository->getDomainNameById($zone_id);

                    // Get all records from the template
                    $templ_records = ZoneTemplate::getZoneTemplRecords($this->db, $zone_template_id);
                    $zoneTemplate = new ZoneTemplate($this->db, $this->config);

                    // Commit before API writes to avoid snapshot isolation issues
                    if ($isApiBackend) {
                        $this->db->commit();
                    }

                    // Process each template record
                    foreach ($templ_records as $r) {
                        // Check if this is a reverse zone and handle NS or SOA records appropriately
                        if ((preg_match('/in-addr.arpa/i', $domain) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!preg_match('/in-addr.arpa/i', $domain))) {
                            $name = $zoneTemplate->parseTemplateValue($r["name"], $domain);
                            $recordType = $r["type"];

                            if ($recordType == "SOA") {
                                if ($isApiBackend) {
                                    // In API mode, SOA is managed by PowerDNS; skip SOA template records
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
                                    $content = $zoneTemplate->parseTemplateValue($r["content"], $domain);
                                }
                            } else {
                                $content = $zoneTemplate->parseTemplateValue($r["content"], $domain);
                            }

                            $ttl = $r["ttl"];
                            $prio = intval($r["prio"]);

                            if (!$ttl) {
                                $ttl = $dns_ttl;
                            }

                            // Check if a record with the same name, type, and content already exists
                            $recordExists = $isApiBackend
                                ? $this->backendProvider->recordExists($zone_id, $name, $recordType, $content)
                                : false;

                            if (!$isApiBackend) {
                                $stmt = $this->db->prepare("SELECT COUNT(*) FROM $records_table
                                WHERE domain_id = :zone_id
                                AND name = :name
                                AND type = :type
                                AND content = :content");
                                $stmt->execute([
                                    ':zone_id' => $zone_id,
                                    ':name' => $name,
                                    ':type' => $recordType,
                                    ':content' => $content
                                ]);
                                $recordExists = (int)$stmt->fetchColumn() > 0;
                            }

                            // Only insert if the record doesn't already exist
                            if (!$recordExists) {
                                if ($isApiBackend) {
                                    try {
                                        $record_id = $this->backendProvider->addRecordGetId($zone_id, $name, $recordType, $content, (int)$ttl, $prio);
                                    } catch (RecordIdNotFoundException $e) {
                                        $this->logger->error('Failed to get record ID after API creation: {error}', ['error' => $e->getMessage()]);
                                        continue;
                                    }
                                    if ($record_id === null) {
                                        continue;
                                    }
                                } else {
                                    // Insert the record via SQL
                                    $stmt = $this->db->prepare("INSERT INTO $records_table (domain_id, name, type, content, ttl, prio) VALUES (:zone_id, :name, :type, :content, :ttl, :prio)");
                                    $stmt->execute([
                                        ':zone_id' => $zone_id,
                                        ':name' => $name,
                                        ':type' => $recordType,
                                        ':content' => $content,
                                        ':ttl' => $ttl,
                                        ':prio' => $prio
                                    ]);

                                    // Get the new record ID
                                    if ($db_type == 'pgsql') {
                                        $record_id = $this->db->lastInsertId('records_id_seq');
                                    } else {
                                        $record_id = $this->db->lastInsertId();
                                    }
                                }

                                // Link the record to the template so future template
                                // changes can remove it precisely. SQL records use the
                                // INT-keyed records_zone_templ; API records use the
                                // string-keyed records_zone_templ_api because their
                                // encoded RecordIdentifier IDs don't fit an INT column.
                                if ($isApiBackend) {
                                    $stmt = $this->db->prepare("INSERT INTO records_zone_templ_api (domain_id, record_id, zone_templ_id) VALUES (:zone_id, :record_id, :zone_template_id)");
                                    $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
                                    $stmt->bindValue(':record_id', (string) $record_id, PDO::PARAM_STR);
                                    $stmt->bindValue(':zone_template_id', $zone_template_id, PDO::PARAM_INT);
                                    $stmt->execute();
                                } else {
                                    $stmt = $this->db->prepare("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (:zone_id, :record_id, :zone_template_id)");
                                    $stmt->execute([
                                        ':zone_id' => $zone_id,
                                        ':record_id' => $record_id,
                                        ':zone_template_id' => $zone_template_id
                                    ]);
                                }

                                $loggedRecordId = $isApiBackend
                                    ? $record_id
                                    : ($record_id !== false ? (int) $record_id : null);
                                $this->captureChange(function () use ($loggedRecordId, $name, $recordType, $content, $ttl, $prio, $zone_id): void {
                                    $this->changeLogger->logRecordCreate([
                                        'id' => $loggedRecordId,
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
            $stmt->execute([
                ':zone_template_id' => $zone_template_id,
                ':zone_id' => $zone_id
            ]);
            if (!$isApiBackend || $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->messageService->addSystemError(sprintf(_('Failed to update zone records: %s'), $e->getMessage()));
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

        if ($deletedMappingIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($deletedMappingIds), '?'));
        $cleanup = $this->db->prepare("DELETE FROM records_zone_templ_api WHERE id IN ($placeholders)");
        foreach ($deletedMappingIds as $i => $id) {
            $cleanup->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $cleanup->execute();
    }
}
