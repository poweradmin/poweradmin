<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Service class for managing domains/zones
 */
class DomainManager implements DomainManagerInterface
{
    private PDOLayer $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private SOARecordManagerInterface $soaRecordManager;
    private DomainRepositoryInterface $domainRepository;
    private IPAddressValidator $ipAddressValidator;

    /**
     * Constructor
     *
     * @param PDOLayer $db Database connection
     * @param ConfigurationManager $config Configuration manager
     * @param SOARecordManagerInterface $soaRecordManager SOA record manager
     * @param DomainRepositoryInterface $domainRepository Domain repository
     */
    public function __construct(
        PDOLayer $db,
        ConfigurationManager $config,
        SOARecordManagerInterface $soaRecordManager,
        DomainRepositoryInterface $domainRepository
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->soaRecordManager = $soaRecordManager;
        $this->domainRepository = $domainRepository;
        $this->ipAddressValidator = new IPAddressValidator();
    }

    /**
     * Add a domain to the database
     *
     * @param object $db Database connection
     * @param string $domain A domain name
     * @param int $owner Owner ID for domain
     * @param string $type Type of domain ['NATIVE','MASTER','SLAVE']
     * @param string $slave_master Master server hostname for domain
     * @param int|string $zone_template ID of zone template ['none' or int]
     *
     * @return boolean true on success
     */
    public function addDomain($db, string $domain, int $owner, string $type, string $slave_master, int|string $zone_template): bool
    {
        $zone_master_add = UserManager::verifyPermission($db, 'zone_master_add');
        $zone_slave_add = UserManager::verifyPermission($db, 'zone_slave_add');

        // TODO: make sure only one is possible if only one is enabled
        if ($zone_master_add || $zone_slave_add) {
            $dns_ns1 = $this->config->get('dns', 'ns1');
            $dns_hostmaster = $this->config->get('dns', 'hostmaster');
            $dns_ttl = $this->config->get('dns', 'ttl');
            $db_type = $this->config->get('database', 'type');

            $pdns_db_name = $this->config->get('database', 'pdns_name');
            $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
            $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

            if (
                ($domain && $owner && $zone_template) ||
                (preg_match('/in-addr.arpa/i', $domain) && $owner && $zone_template) ||
                $type == "SLAVE" && $domain && $owner && $slave_master
            ) {
                $stmt = $db->prepare("INSERT INTO $domains_table (name, type) VALUES (:domain, :type)");
                $stmt->bindValue(':domain', $domain, \PDO::PARAM_STR);
                $stmt->bindValue(':type', $type, \PDO::PARAM_STR);
                $stmt->execute();

                $domain_id = $db->lastInsertId();

                $stmt = $db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (:domain_id, :owner, :zone_template)");
                $stmt->bindValue(':domain_id', $domain_id, \PDO::PARAM_INT);
                $stmt->bindValue(':owner', $owner, \PDO::PARAM_INT);
                $stmt->bindValue(':zone_template', ($zone_template == "none") ? 0 : $zone_template, \PDO::PARAM_INT);
                $stmt->execute();

                if ($type == "SLAVE") {
                    $stmt = $db->prepare("UPDATE $domains_table SET master = :slave_master WHERE id = :domain_id");
                    $stmt->bindValue(':slave_master', $slave_master, \PDO::PARAM_STR);
                    $stmt->bindValue(':domain_id', $domain_id, \PDO::PARAM_INT);
                    $stmt->execute();
                    return true;
                } else {
                    if ($zone_template == "none" && $domain_id) {
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

                        $query = "INSERT INTO $records_table (domain_id, name, content, type, ttl, prio) VALUES ("
                            . $db->quote($domain_id, 'integer') . ","
                            . $db->quote($domain, 'text') . ","
                            . $db->quote($soa_content, 'text') . ","
                            . $db->quote('SOA', 'text') . ","
                            . $db->quote($ttl, 'integer') . ","
                            . $db->quote(0, 'integer') . ")";
                        $db->query($query);
                        return true;
                    } elseif ($domain_id && is_numeric($zone_template)) {
                        $dns_ttl = $this->config->get('dns', 'ttl');

                        $templ_records = ZoneTemplate::getZoneTemplRecords($db, $zone_template);
                        if ($templ_records != -1) {
                            foreach ($templ_records as $r) {
                                if ((preg_match('/in-addr.arpa/i', $domain) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!preg_match('/in-addr.arpa/i', $domain))) {
                                    $zoneTemplate = new ZoneTemplate($this->db, $this->config);
                                    $name = $zoneTemplate->parseTemplateValue($r["name"], $domain);
                                    $type = $r["type"];
                                    $content = $zoneTemplate->parseTemplateValue($r["content"], $domain);
                                    $ttl = $r["ttl"];
                                    $prio = intval($r["prio"]);

                                    if (!$ttl) {
                                        $ttl = $dns_ttl;
                                    }

                                    $query = "INSERT INTO $records_table (domain_id, name, type, content, ttl, prio) VALUES ("
                                        . $db->quote($domain_id, 'integer') . ","
                                        . $db->quote($name, 'text') . ","
                                        . $db->quote($type, 'text') . ","
                                        . $db->quote($content, 'text') . ","
                                        . $db->quote($ttl, 'integer') . ","
                                        . $db->quote($prio, 'integer') . ")";
                                    $db->query($query);

                                    $record_id = $db->lastInsertId();

                                    $query = "INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES ("
                                        . $db->quote($domain_id, 'integer') . ","
                                        . $db->quote($record_id, 'integer') . ","
                                        . $db->quote($r['zone_templ_id'], 'integer') . ")";
                                    $db->query($query);
                                }
                            }
                        }
                        return true;
                    } else {
                        $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "addDomain", "could not create zone"));
                        return false;
                    }
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

        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
            $this->db->query("DELETE FROM zones WHERE domain_id=" . $this->db->quote($id, 'integer'));
            $this->db->query("DELETE FROM $records_table WHERE domain_id=" . $this->db->quote($id, 'integer'));
            $this->db->query("DELETE FROM records_zone_templ WHERE domain_id=" . $this->db->quote($id, 'integer'));
            $this->db->query("DELETE FROM $domains_table WHERE id=" . $this->db->quote($id, 'integer'));
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
        $pdnssec_use = $this->config->get('dnssec', 'enabled');
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $domains_table = $pdns_db_name ? "$pdns_db_name.domains" : "domains";
        $records_table = $pdns_db_name ? "$pdns_db_name.records" : "records";

        $this->db->beginTransaction();

        foreach ($domains as $id) {
            $perm_edit = Permission::getEditPermission($this->db);
            $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $id);

            if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
                if (is_numeric($id)) {
                    $zone_type = $this->domainRepository->getDomainType($id);
                    if ($pdnssec_use && $zone_type == 'MASTER') {
                        $pdns_api_url = $this->config->get('pdns_api', 'url');
                        $pdns_api_key = $this->config->get('pdns_api', 'key');

                        $dnssecProvider = DnssecProviderFactory::create(
                            $this->db,
                            new FakeConfiguration($pdns_api_url, $pdns_api_key)
                        );

                        $zone_name = $this->domainRepository->getDomainNameById($id);
                        if ($dnssecProvider->isZoneSecured($zone_name, $this->config)) {
                            $dnssecProvider->unsecureZone($zone_name);
                        }
                    }

                    $this->db->exec("DELETE FROM zones WHERE domain_id=" . $this->db->quote($id, 'integer'));
                    $this->db->exec("DELETE FROM $records_table WHERE domain_id=" . $this->db->quote($id, 'integer'));
                    $this->db->query("DELETE FROM records_zone_templ WHERE domain_id=" . $this->db->quote($id, 'integer'));
                    $this->db->exec("DELETE FROM $domains_table WHERE id=" . $this->db->quote($id, 'integer'));
                } else {
                    $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "deleteDomains", "id must be a number"));
                }
            } else {
                $this->messageService->addSystemError(_("You do not have the permission to delete a zone."));
            }
        }

        $this->db->commit();

        return true;
    }

    /**
     * Change Zone Type
     *
     * @param string $type New Zone Type [NATIVE,MASTER,SLAVE]
     * @param int $id Zone ID
     */
    public function changeZoneType(string $type, int $id): void
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $add = '';
        $params = array(':type' => $type, ':id' => $id);

        // It is not really necessary to clear the field that contains the IP address
        // of the master if the type changes from slave to something else. PowerDNS will
        // ignore the field if the type isn't something else then slave. But then again,
        // it's much clearer this way.
        if ($type != "SLAVE") {
            $add = ", master = :master";
            $params[':master'] = '';
        }
        $query = "UPDATE $domains_table SET type = :type" . $add . " WHERE id = :id";
        $stmt = $this->db->prepare($query);

        $stmt->execute($params);
    }

    /**
     * Change Slave Zone's Master IP Address
     *
     * @param int $zone_id Zone ID
     * @param string $ip_slave_master Master IP Address
     */
    public function changeZoneSlaveMaster(int $zone_id, string $ip_slave_master)
    {
        if ($this->ipAddressValidator->areMultipleValidIPs($ip_slave_master)) {
            $pdns_db_name = $this->config->get('database', 'pdns_name');
            $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

            $stmt = $this->db->prepare("UPDATE $domains_table SET master = ? WHERE id = ?");
            $stmt->execute(array($ip_slave_master, $zone_id));
        } else {
            $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "changeZoneSlaveMaster", "This is not a valid IPv4 or IPv6 address: $ip_slave_master"));
        }
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
        if ((UserManager::verifyPermission($db, 'zone_meta_edit_others')) || (UserManager::verifyPermission($db, 'zone_meta_edit_own')) && UserManager::verifyUserIsOwnerZoneId($db, $_GET["id"])) {
            if (UserManager::isValidUser($db, $user_id)) {
                if ($db->queryOne("SELECT COUNT(id) FROM zones WHERE owner=" . $db->quote($user_id, 'integer') . " AND domain_id=" . $db->quote($zone_id, 'integer')) == 0) {
                    $zone_templ_id = self::getZoneTemplate($db, $zone_id);
                    if ($zone_templ_id == null) {
                        $zone_templ_id = 0;
                    }
                    $stmt = $db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES(:zone_id, :user_id, :zone_templ_id)");
                    $stmt->execute([
                        "zone_id" => $zone_id,
                        "user_id" => $user_id,
                        "zone_templ_id" => $zone_templ_id,
                    ]);
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
        if ((UserManager::verifyPermission($db, 'zone_meta_edit_others')) || (UserManager::verifyPermission($db, 'zone_meta_edit_own')) && UserManager::verifyUserIsOwnerZoneId($db, $_GET["id"])) {
            if (UserManager::isValidUser($db, $user_id)) {
                if ($db->queryOne("SELECT COUNT(id) FROM zones WHERE domain_id=" . $db->quote($zone_id, 'integer')) > 1) {
                    $db->query("DELETE FROM zones WHERE owner=" . $db->quote($user_id, 'integer') . " AND domain_id=" . $db->quote($zone_id, 'integer'));
                } else {
                    $messageService = new MessageService();
                    $messageService->addSystemError(_('There must be at least one owner for a zone.'));
                }
                return true;
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
     * @return int Zone Template ID
     */
    public static function getZoneTemplate($db, int $zone_id): int
    {
        $query = "SELECT zone_templ_id FROM zones WHERE domain_id = " . $db->quote($zone_id, 'integer');
        return $db->queryOne($query);
    }

    /**
     * Update All Zone Records for Zone ID with Zone Template
     *
     * @param string $db_type Database type
     * @param int $dns_ttl Default TTL
     * @param int $zone_id Zone ID to update
     * @param int $zone_template_id Zone Template ID to use for update
     */
    public function updateZoneRecords(string $db_type, int $dns_ttl, int $zone_id, int $zone_template_id)
    {
        $perm_edit = Permission::getEditPermission($this->db);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        if (UserManager::verifyPermission($this->db, 'zone_master_add')) {
            $zone_master_add = "1";
        }

        if (UserManager::verifyPermission($this->db, 'zone_slave_add')) {
            $zone_slave_add = "1";
        }

        $soa_rec = $this->soaRecordManager->getSOARecord($zone_id);
        $this->db->beginTransaction();

        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        if ($zone_template_id != 0) {
            if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
                if ($db_type == 'pgsql') {
                    $query = "DELETE FROM $records_table r USING records_zone_templ rzt WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id AND r.id = rzt.record_id";
                } elseif ($db_type == 'sqlite') {
                    $query = "DELETE FROM $records_table WHERE id IN (SELECT r.id FROM $records_table r LEFT JOIN records_zone_templ rzt ON r.id = rzt.record_id WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id)";
                } else {
                    $query = "DELETE r, rzt FROM $records_table r LEFT JOIN records_zone_templ rzt ON r.id = rzt.record_id WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id";
                }
                $stmt = $this->db->prepare($query);
                $stmt->execute(array(':zone_id' => $zone_id, ':zone_template_id' => $zone_template_id));
            } else {
                $this->messageService->addSystemError(_("You do not have the permission to delete a zone."));
            }
            if ($zone_master_add == "1" || $zone_slave_add == "1") {
                $domain = $this->domainRepository->getDomainNameById($zone_id);
                $templ_records = ZoneTemplate::getZoneTemplRecords($this->db, $zone_template_id);

                foreach ($templ_records as $r) {
                    // Check if this is a reverse zone and handle NS or SOA records appropriately
                    if ((preg_match('/in-addr.arpa/i', $domain) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!preg_match('/in-addr.arpa/i', $domain))) {
                        $zoneTemplate = new ZoneTemplate($this->db, $this->config);
                        $name = $zoneTemplate->parseTemplateValue($r["name"], $domain);
                        $type = $r["type"];
                        if ($type == "SOA") {
                            $this->db->exec("DELETE FROM $records_table WHERE domain_id = " . $this->db->quote($zone_id, 'integer') . " AND type = 'SOA'");
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

                        $query = "INSERT INTO $records_table (domain_id, name, type, content, ttl, prio) VALUES ("
                            . $this->db->quote($zone_id, 'integer') . ","
                            . $this->db->quote($name, 'text') . ","
                            . $this->db->quote($type, 'text') . ","
                            . $this->db->quote($content, 'text') . ","
                            . $this->db->quote($ttl, 'integer') . ","
                            . $this->db->quote($prio, 'integer') . ")";
                        $this->db->exec($query);

                        if ($db_type == 'pgsql') {
                            $record_id = $this->db->lastInsertId('records_id_seq');
                        } else {
                            $record_id = $this->db->lastInsertId();
                        }

                        $query = "INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES ("
                            . $this->db->quote($zone_id, 'integer') . ","
                            . $this->db->quote($record_id, 'integer') . ","
                            . $this->db->quote($zone_template_id, 'integer') . ")";
                        $this->db->query($query);
                    }
                }
            }
        }

        $query = "UPDATE zones
                    SET zone_templ_id = " . $this->db->quote($zone_template_id, 'integer') . "
                    WHERE domain_id = " . $this->db->quote($zone_id, 'integer');
        $this->db->exec($query);
        $this->db->commit();
    }
}
