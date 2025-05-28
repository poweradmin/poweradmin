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

namespace Poweradmin\Domain\Repository;

use Poweradmin\Domain\Model\Constants;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Utility\SortHelper;

/**
 * Repository class for domain/zone operations
 */
class DomainRepository implements DomainRepositoryInterface
{

    private PDOCommon $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;

    /**
     * Constructor
     *
     * @param PDOCommon $db Database connection
     * @param ConfigurationManager $config Configuration manager
     */
    public function __construct(PDOCommon $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->hostnameValidator = new HostnameValidator($config);
    }

    /**
     * Check if Zone ID exists
     *
     * @param int $zid Zone ID
     *
     * @return int Domain count or false on failure
     */
    public function zoneIdExists(int $zid): int
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $stmt = $this->db->prepare("SELECT COUNT(id) FROM $domains_table WHERE id = :id");
        $stmt->execute([':id' => $zid]);
        return $stmt->fetchColumn();
    }

    /**
     * Get Domain Name by domain ID
     *
     * @param int $id Domain ID
     *
     * @return string|null Domain name or null if not found
     */
    public function getDomainNameById(int $id): ?string
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $stmt = $this->db->prepare("SELECT name FROM $domains_table WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        if ($result) {
            return $result["name"];
        } else {
            return null;
        }
    }

    /**
     * Get Domain ID by name
     *
     * @param string $name Domain name
     *
     * @return int|null Domain ID or null if not found
     */
    public function getDomainIdByName(string $name): ?int
    {
        if (empty($name)) {
            return null;
        }

        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $query = "SELECT id FROM $domains_table WHERE name = :name";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':name', $name);
        $stmt->execute();

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? (int)$result['id'] : null;
    }

    /**
     * Get zone id from name
     *
     * @param string $zname Zone name
     * @return int|null Zone ID or null if not found
     */
    public function getZoneIdFromName(string $zname): ?int
    {
        if (empty($zname)) {
            return null;
        }

        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $stmt = $this->db->prepare("SELECT id FROM $domains_table WHERE name = :name");
        $stmt->execute([':name' => $zname]);
        $result = $stmt->fetch();

        return $result ? (int)$result["id"] : null;
    }

    /**
     * Get Domain Type for Domain ID
     *
     * @param int $id Domain ID
     *
     * @return string Domain Type [NATIVE,MASTER,SLAVE]
     */
    public function getDomainType(int $id): string
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $stmt = $this->db->prepare("SELECT type FROM $domains_table WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $type = $stmt->fetchColumn();
        if ($type == "") {
            $type = "NATIVE";
        }
        return $type;
    }

    /**
     * Get Slave Domain's Master
     *
     * @param int $id Domain ID
     *
     * @return string|null Master server or null if not found
     */
    public function getDomainSlaveMaster(int $id): ?string
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $stmt = $this->db->prepare("SELECT master FROM $domains_table WHERE type = 'SLAVE' and id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    /**
     * Check if a domain is already existing.
     *
     * @param string $domain Domain name
     * @return boolean true if existing, false if it doesn't exist.
     */
    public function domainExists(string $domain): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        if ($this->hostnameValidator->isValid($domain)) {
            $stmt = $this->db->prepare("SELECT id FROM $domains_table WHERE name = :name");
            $stmt->execute([':name' => $domain]);
            $result = $stmt->fetch();
            return (bool)$result;
        } else {
            $this->messageService->addSystemError(_('This is an invalid zone name.'));
            return false;
        }
    }

    /**
     * Get Zones
     *
     * @param string $perm View Zone Permissions ['own','all','none']
     * @param int $userid Requesting User ID
     * @param string $letterstart Starting letters to match [default='all']
     * @param int $rowstart Start from row in set [default=0]
     * @param int $rowamount Max number of rows to fetch for this query when not 'all' [default=9999]
     * @param string $sortby Column to sort results by [default='name']
     * @param string $sortDirection Sort direction [default='ASC']
     *
     * @return array array of zone details [id,name,type,count_records] (empty array if none found)
     */
    public function getZones(string $perm, int $userid = 0, string $letterstart = 'all', int $rowstart = 0, int $rowamount = Constants::DEFAULT_MAX_ROWS, string $sortby = 'name', string $sortDirection = 'ASC'): array
    {
        $db_type = $this->config->get('database', 'type');
        $pdnssec_use = $this->config->get('dnssec', 'enabled');
        $iface_zone_comments = $this->config->get('interface', 'show_zone_comments');
        $iface_zonelist_serial = $this->config->get('interface', 'display_serial_in_zone_list');
        $iface_zonelist_template = $this->config->get('interface', 'display_template_in_zone_list');

        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';
        $cryptokeys_table = $pdns_db_name ? $pdns_db_name . '.cryptokeys' : 'cryptokeys';
        $domainmetadata_table = $pdns_db_name ? $pdns_db_name . '.domainmetadata' : 'domainmetadata';

        if ($letterstart == '_') {
            $letterstart = '\_';
        }

        $sql_add = '';
        if ($perm != "own" && $perm != "all") {
            return [];
        } else {
            $params = [];
            if ($perm == "own") {
                $sql_add = " AND zones.domain_id = $domains_table.id AND zones.owner = :userid";
                $params[':userid'] = $userid;
            }

            if ($letterstart != 'all' && $letterstart != 1) {
                $sql_add .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) = :letterstart ";
                $params[':letterstart'] = $letterstart;
            } elseif ($letterstart == 1) {
                $sql_add .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) " . DbCompat::regexp($db_type) . " '[0-9]'";
            }
        }

        if ($sortby == 'owner') {
            $sortby = 'users.username';
        } elseif ($sortby == 'count_records') {
            $sortby = "COUNT($records_table.id)";
        } else {
            $sortby = "$domains_table.$sortby";
        }

        $sql_sortby = $sortby == "$domains_table.name" ? SortHelper::getZoneSortOrder($domains_table, $db_type, $sortDirection) : $sortby . " " . $sortDirection;

        $query = "SELECT $domains_table.id,
                        $domains_table.name,
                        $domains_table.type,
                        COUNT($records_table.id) AS count_records,
                        users.username,
                        users.fullname
                        " . ($pdnssec_use ? ", COUNT($cryptokeys_table.id) > 0 OR COUNT($domainmetadata_table.id) > 0 AS secured" : "") . "
                        " . ($iface_zone_comments ? ", zones.comment" : "") . "
                        FROM $domains_table
                        LEFT JOIN zones ON $domains_table.id=zones.domain_id
                        LEFT JOIN $records_table ON $records_table.domain_id=$domains_table.id AND $records_table.type IS NOT NULL
                        LEFT JOIN users ON users.id=zones.owner";

        if ($pdnssec_use) {
            $query .= " LEFT JOIN $cryptokeys_table ON $domains_table.id = $cryptokeys_table.domain_id AND $cryptokeys_table.active
                        LEFT JOIN $domainmetadata_table ON $domains_table.id = $domainmetadata_table.domain_id AND $domainmetadata_table.kind = 'PRESIGNED'";
        }

        $query .= " WHERE 1=1" . $sql_add . "
                    GROUP BY $domains_table.name, $domains_table.id, $domains_table.type, users.username, users.fullname
                    " . ($iface_zone_comments ? ", zones.comment" : "") . "
                    ORDER BY " . $sql_sortby;

        if ($letterstart != 'all' && $rowamount < Constants::DEFAULT_MAX_ROWS) {
            $query .= " LIMIT " . $rowamount;
            if ($rowstart > 0) {
                $query .= " OFFSET " . $rowstart;
            }
        }

        if (!empty($params)) {
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $result = $stmt;
        } else {
            $result = $this->db->query($query);
        }

        $ret = array();
        while ($r = $result->fetch()) {
            //FIXME: name is not guaranteed to be unique with round-robin record sets
            $domainName = $r["name"];
            $utf8Name = DnsIdnService::toUtf8($domainName);

            $ret[$domainName]["id"] = $r["id"];
            $ret[$domainName]["name"] = $domainName;
            $ret[$domainName]["utf8_name"] = $utf8Name;
            $ret[$domainName]["type"] = $r["type"];
            $ret[$domainName]["count_records"] = $r["count_records"];
            $ret[$domainName]["comment"] = $r["comment"] ?? '';
            $ret[$domainName]["owners"][] = $r["username"];
            $ret[$domainName]["full_names"][] = $r["fullname"] ?: '';
            $ret[$domainName]["users"][] = $r["username"];

            if ($pdnssec_use) {
                $ret[$domainName]["secured"] = $r["secured"];
            }

            if ($iface_zonelist_serial) {
                // Create RecordRepository to get the serial
                $recordRepository = new RecordRepository($this->db, $this->config);
                $ret[$domainName]["serial"] = $recordRepository->getSerialByZid($r["id"]);
            }

            if ($iface_zonelist_template) {
                $ret[$domainName]["template"] = ZoneTemplate::getZoneTemplName($this->db, $r["id"]);
            }
        }

        return $ret;
    }

    /**
     * Get Zone details from Zone ID
     *
     * @param int $zid Zone ID
     * @return array array of zone details [type,name,master_ip,record_count]
     */
    public function getZoneInfoFromId(int $zid): array
    {
        $perm_view = Permission::getViewPermission($this->db);

        if ($perm_view == "none") {
            $this->messageService->addSystemError(_("You do not have the permission to view this zone."));
            return [];
        } else {
            $pdns_db_name = $this->config->get('database', 'pdns_db_name');
            $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
            $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

            $stmt = $this->db->prepare("SELECT $domains_table.type AS type,
                    $domains_table.name AS name,
                    $domains_table.master AS master_ip,
                    count($records_table.domain_id) AS record_count
                    FROM $domains_table LEFT OUTER JOIN $records_table ON $domains_table.id = $records_table.domain_id
                    WHERE $domains_table.id = :zid
                    GROUP BY $domains_table.id, $domains_table.type, $domains_table.name, $domains_table.master");
            $stmt->execute([':zid' => $zid]);
            $result = $stmt->fetch();
            return array(
                "id" => $zid,
                "name" => $result['name'],
                "type" => $result['type'],
                "master_ip" => $result['master_ip'],
                "record_count" => $result['record_count']
            );
        }
    }

    /**
     * Get Zone(s) details from Zone IDs
     *
     * @param array $zones Zone IDs
     * @return array
     */
    public function getZoneInfoFromIds(array $zones): array
    {
        $zone_infos = array();
        foreach ($zones as $zone) {
            $zone_info = $this->getZoneInfoFromId($zone);
            $zone_infos[] = $zone_info;
        }
        return $zone_infos;
    }

    /**
     * Get Best Matching in-addr.arpa Zone ID from Domain Name
     *
     * @param string $domain Domain name
     *
     * @return int Zone ID or -1 if not found
     */
    public function getBestMatchingZoneIdFromName(string $domain): int
    {
        // rev-patch
        // string to find the correct zone
        // %ip6.arpa and %in-addr.arpa is looked for

        $match = 72; // the longest ip6.arpa has a length of 72
        $found_domain_id = -1;

        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        // get all reverse-zones
        $stmt = $this->db->prepare("SELECT name, id FROM $domains_table
                   WHERE name like :pattern
                   ORDER BY length(name) DESC");
        $stmt->execute([':pattern' => '%.arpa']);
        $response = $stmt;
        if ($response) {
            while ($r = $response->fetch()) {
                $pos = stripos($domain, $r["name"]);
                if ($pos !== false) {
                    // one possible searched $domain is found
                    if ($pos < $match) {
                        $match = $pos;
                        $found_domain_id = $r["id"];
                    }
                }
            }
        } else {
            return -1;
        }
        return $found_domain_id;
    }
}
