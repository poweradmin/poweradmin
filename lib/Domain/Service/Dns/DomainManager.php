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
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        ?LoggerInterface $logger = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->soaRecordManager = $soaRecordManager;
        $this->domainRepository = $domainRepository;
        $this->ipAddressValidator = new IPAddressValidator();
        $this->backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        $this->logger = $logger ?? new NullLogger();
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
                        $syncService = new ZoneTemplateSyncService($db, $this->config);
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

                            $this->soaRecordManager->setTimezone();
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
                            if (is_array($templ_records) && !empty($templ_records)) {
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

                                        // Skip template linkage in API mode: record IDs are encoded
                                        // strings that can't be stored in the integer record_id column.
                                        if (!$isApiBackend) {
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
                    if ($domain_id !== false) {
                        $this->cleanupZoneOnFailure($domain_id, $domain);
                        // In API mode, the zones row is inserted before beginTransaction(),
                        // so rollBack() won't remove it. Always clean up metadata in that case.
                        if (!$wasInTransaction || $this->backendProvider->isApiBackend()) {
                            $this->cleanupZoneMetadata($domain_id);
                        }
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
                    $syncService = new ZoneTemplateSyncService($this->db, $this->config);
                    $syncService->cleanupZoneSyncRecords($zoneId);
                }

                $stmt = $this->db->prepare("DELETE FROM zones WHERE domain_id = :id");
                $stmt->execute([':id' => $id]);

                $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :id");
                $stmt->execute([':id' => $id]);

                $stmt = $this->db->prepare("DELETE FROM records_zone_templ WHERE domain_id = :id");
                $stmt->execute([':id' => $id]);

                $this->db->commit();
            } catch (\Exception $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $this->messageService->addSystemError(sprintf(_('Failed to clean up zone metadata: %s'), $e->getMessage()));
                return false;
            }

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
                if (is_numeric($id)) {
                    // Get zone name for backend deletion.
                    $zoneName = $this->domainRepository->getDomainNameById($id);
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
                            $syncService = new ZoneTemplateSyncService($this->db, $this->config);
                            $syncService->cleanupZoneSyncRecords($zoneId);
                        }

                        // Clean up Poweradmin-internal tables (always SQL)
                        $stmt = $this->db->prepare("DELETE FROM zones WHERE domain_id = :id");
                        $stmt->execute([':id' => $id]);

                        $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :id");
                        $stmt->execute([':id' => $id]);

                        $stmt = $this->db->prepare("DELETE FROM records_zone_templ WHERE domain_id = :id");
                        $stmt->execute([':id' => $id]);

                        $this->db->commit();
                    } catch (\Exception $e) {
                        if ($this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                        $this->messageService->addSystemError(sprintf(_('Failed to delete zone metadata: %s'), $e->getMessage()));
                        $allSucceeded = false;
                    }
                } else {
                    $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "deleteDomains", "id must be a number"));
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
            $db->prepare("DELETE FROM zones_groups WHERE domain_id = :did")->execute([':did' => $domainId]);
            $db->prepare("DELETE FROM zones WHERE domain_id = :did")->execute([':did' => $domainId]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to clean up zone metadata for domain_id {domainId}: {error}', ['domainId' => $domainId, 'error' => $e->getMessage()]);
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
        if (!$this->backendProvider->updateZoneType($id, $type)) {
            $this->messageService->addSystemError(_('Failed to update zone type in DNS backend.'));
            return false;
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

        if (!$this->backendProvider->updateZoneMaster($zone_id, $ip_slave_master)) {
            $this->messageService->addSystemError(_('Failed to update zone master in DNS backend.'));
            return false;
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
                        // In API mode: resolve template records by attributes and delete matches.
                        // The records_zone_templ mapping table has no entries for API zones
                        // (encoded string IDs can't be stored in INT column), so we match
                        // template definitions against actual zone records instead.
                        $this->deleteTemplateRecordsViaApi($zone_id, $zone_template_id, $dns_ttl);
                    } else {
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
                                $stmt = $this->db->prepare("DELETE FROM $records_table WHERE domain_id = :zone_id AND type = 'SOA'");
                                $stmt->execute([':zone_id' => $zone_id]);
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

                                // Link the record to the template in the mapping table
                                // Skip for API-backed records: encoded string IDs can't be stored in INT column
                                if (!$isApiBackend) {
                                    $stmt = $this->db->prepare("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (:zone_id, :record_id, :zone_template_id)");
                                    $stmt->execute([
                                        ':zone_id' => $zone_id,
                                        ':record_id' => $record_id,
                                        ':zone_template_id' => $zone_template_id
                                    ]);
                                }
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
     * Delete records that match a zone template's definitions via the API backend.
     *
     * Since API-mode record IDs are encoded strings that can't be stored in the
     * integer records_zone_templ.record_id column, this method resolves template
     * records by attributes (name/type/content) and deletes matches from the backend.
     */
    private function deleteTemplateRecordsViaApi(int $zone_id, int $zone_template_id, int $dns_ttl): void
    {
        $domain = $this->domainRepository->getDomainNameById($zone_id);
        if (!is_string($domain)) {
            return;
        }

        $templ_records = ZoneTemplate::getZoneTemplRecords($this->db, $zone_template_id);
        if (empty($templ_records)) {
            return;
        }

        $zoneTemplate = new ZoneTemplate($this->db, $this->config);

        // Build a set of expected name/type/content tuples from the template
        $expectedRecords = [];
        foreach ($templ_records as $r) {
            $type = $r['type'];
            if ($type === 'SOA') {
                continue; // SOA is managed by PowerDNS in API mode
            }
            $name = $zoneTemplate->parseTemplateValue($r['name'], $domain);
            $content = $zoneTemplate->parseTemplateValue($r['content'], $domain);
            $expectedRecords[] = [
                'name' => $name,
                'type' => $type,
                'content' => $content,
            ];
        }

        if (empty($expectedRecords)) {
            return;
        }

        // Fetch all zone records from the API and delete matches
        $zoneRecords = $this->backendProvider->getRecordsByZoneId($zone_id);
        foreach ($zoneRecords as $record) {
            foreach ($expectedRecords as $expected) {
                if (
                    ($record['name'] ?? '') === $expected['name']
                    && ($record['type'] ?? '') === $expected['type']
                    && ($record['content'] ?? '') === $expected['content']
                ) {
                    $this->backendProvider->deleteRecord($record['id']);
                    break;
                }
            }
        }
    }
}
