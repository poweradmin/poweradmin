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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Model\Constants;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Utility\SortHelper;

/**
 * SQL-backend domain repository.
 * Queries PowerDNS tables directly via PDO.
 */
class SqlDomainRepository implements DomainRepositoryInterface
{
    private PDO $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;
    private TableNameService $tableNameService;

    public function __construct(PDO $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->hostnameValidator = new HostnameValidator($config);
        $this->tableNameService = new TableNameService($config);
    }

    public function zoneIdExists(int $zid): int
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->prepare("SELECT COUNT(id) FROM $domains_table WHERE id = :id");
        $stmt->execute([':id' => $zid]);
        return (int)$stmt->fetchColumn();
    }

    public function getDomainNameById(int $id): ?string
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->prepare("SELECT name FROM $domains_table WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        if ($result) {
            return $result["name"];
        } else {
            return null;
        }
    }

    public function getDomainIdByName(string $name): ?int
    {
        if (empty($name)) {
            return null;
        }

        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "SELECT id FROM $domains_table WHERE name = :name";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':name', $name);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['id'] : null;
    }

    public function getZoneIdFromName(string $zname): ?int
    {
        if (empty($zname)) {
            return null;
        }

        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->prepare("SELECT id FROM $domains_table WHERE name = :name");
        $stmt->execute([':name' => $zname]);
        $result = $stmt->fetch();

        return $result ? (int)$result["id"] : null;
    }

    public function getDomainType(int $id): string
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->prepare("SELECT type FROM $domains_table WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $type = $stmt->fetchColumn();
        if ($type == "") {
            $type = "NATIVE";
        }
        return $type;
    }

    public function getDomainSlaveMaster(int $id): ?string
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->prepare("SELECT master FROM $domains_table WHERE type = 'SLAVE' and id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    public function domainExists(string $domain): bool
    {
        if (!$this->hostnameValidator->isValid($domain)) {
            $this->messageService->addSystemError(_('This is an invalid zone name.'));
            return false;
        }

        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $stmt = $this->db->prepare("SELECT id FROM $domains_table WHERE name = :name");
        $stmt->execute([':name' => $domain]);
        return (bool)$stmt->fetch();
    }

    public function getZones(
        string $perm,
        int $userid = 0,
        string $letterstart = 'all',
        int $rowstart = 0,
        int $rowamount = Constants::DEFAULT_MAX_ROWS,
        string $sortby = 'name',
        string $sortDirection = 'ASC',
        bool $excludeReverse = false,
        ?bool $showSerial = null,
        ?bool $showTemplate = null
    ): array {
        $allowedSortColumns = ['name', 'type', 'count_records', 'owner'];
        $sortby = $this->tableNameService->validateOrderBy($sortby, $allowedSortColumns);
        $sortDirection = $this->tableNameService->validateDirection($sortDirection);

        $db_type = $this->config->get('database', 'type');
        $pdnssec_use = $this->config->get('dnssec', 'enabled');
        $iface_zone_comments = $this->config->get('interface', 'show_zone_comments');
        $iface_zonelist_serial = $showSerial ?? $this->config->get('interface', 'display_serial_in_zone_list');
        $iface_zonelist_template = $showTemplate ?? $this->config->get('interface', 'display_template_in_zone_list');

        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $cryptokeys_table = $this->tableNameService->getTable(PdnsTable::CRYPTOKEYS);
        $domainmetadata_table = $this->tableNameService->getTable(PdnsTable::DOMAINMETADATA);

        if ($letterstart == '_') {
            $letterstart = '\_';
        }

        $sql_add = '';
        if ($perm != "own" && $perm != "all") {
            return [];
        } else {
            $params = [];
            if ($perm == "own") {
                $sql_add = " AND zones.domain_id = $domains_table.id AND (zones.owner = :userid OR EXISTS (
                    SELECT 1 FROM zones_groups zg
                    INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                    WHERE zg.domain_id = $domains_table.id AND ugm.user_id = :userid_group
                ))";
                $params[':userid'] = $userid;
                $params[':userid_group'] = $userid;
            }

            if ($letterstart != 'all' && $letterstart != 1) {
                $sql_add .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) = :letterstart ";
                $params[':letterstart'] = $letterstart;
            } elseif ($letterstart == 1) {
                $sql_add .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) " . DbCompat::regexp($db_type) . " '[0-9]'";
            }

            if ($excludeReverse) {
                $sql_add .= " AND $domains_table.name NOT LIKE '%.in-addr.arpa' AND $domains_table.name NOT LIKE '%.ip6.arpa'";
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

        if ($letterstart != 'all' && $rowamount < Constants::DEFAULT_MAX_ROWS) {
            $originalSqlMode = DbCompat::handleSqlMode($this->db, $db_type);

            if ($db_type == 'pgsql' && $sortby == "$domains_table.name") {
                $id_query = "SELECT DISTINCT $domains_table.id, $domains_table.name
                            FROM $domains_table";

                if ($perm == "own") {
                    $id_query .= " LEFT JOIN zones ON $domains_table.id = zones.domain_id";
                }

                $id_query .= " WHERE 1=1";

                if ($perm == "own") {
                    $id_query .= " AND (zones.owner = :userid OR EXISTS (
                        SELECT 1 FROM zones_groups zg
                        INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                        WHERE zg.domain_id = $domains_table.id AND ugm.user_id = :userid_group
                    ))";
                    if (!isset($params[':userid_group'])) {
                        $params[':userid_group'] = $userid;
                    }
                }

                if ($letterstart != 'all' && $letterstart != 1) {
                    $id_query .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) = :letterstart";
                } elseif ($letterstart == 1) {
                    $id_query .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) " . DbCompat::regexp($db_type) . " '[0-9]'";
                }

                if ($excludeReverse) {
                    $id_query .= " AND $domains_table.name NOT LIKE '%.in-addr.arpa' AND $domains_table.name NOT LIKE '%.ip6.arpa'";
                }

                $id_query .= " ORDER BY $domains_table.name " . $sortDirection;
                $id_query .= " LIMIT " . intval($rowamount) . " OFFSET " . intval($rowstart);
            } else {
                if ($db_type === 'mysql' && (strpos($sql_sortby, 'users.username') !== false || strpos($sql_sortby, 'COUNT(') !== false)) {
                    $id_query = "SELECT DISTINCT $domains_table.id, $domains_table.name
                                FROM $domains_table";
                } else {
                    $select_columns = "$domains_table.id";
                    if (strpos($sql_sortby, "$domains_table.name") !== false) {
                        $select_columns .= ", $domains_table.name";
                    } elseif (strpos($sql_sortby, "$domains_table.type") !== false) {
                        $select_columns .= ", $domains_table.type";
                    }

                    $id_query = "SELECT DISTINCT $select_columns
                                FROM $domains_table";
                }

                if ($perm == "own") {
                    $id_query .= " LEFT JOIN zones ON $domains_table.id = zones.domain_id";
                }

                $id_query .= " WHERE 1=1";

                if ($perm == "own") {
                    $id_query .= " AND (zones.owner = :userid OR EXISTS (
                        SELECT 1 FROM zones_groups zg
                        INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                        WHERE zg.domain_id = $domains_table.id AND ugm.user_id = :userid_group
                    ))";
                    if (!isset($params[':userid_group'])) {
                        $params[':userid_group'] = $userid;
                    }
                }

                if ($letterstart != 'all' && $letterstart != 1) {
                    $id_query .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) = :letterstart";
                } elseif ($letterstart == 1) {
                    $id_query .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) " . DbCompat::regexp($db_type) . " '[0-9]'";
                }

                if ($excludeReverse) {
                    $id_query .= " AND $domains_table.name NOT LIKE '%.in-addr.arpa' AND $domains_table.name NOT LIKE '%.ip6.arpa'";
                }

                if ($db_type === 'mysql' && (strpos($sql_sortby, 'users.username') !== false || strpos($sql_sortby, 'COUNT(') !== false)) {
                    $id_query .= " ORDER BY $domains_table.name " . $sortDirection;
                } elseif (strpos($sql_sortby, 'users.username') === false && strpos($sql_sortby, 'COUNT(') === false) {
                    $id_query .= " ORDER BY " . $sql_sortby;
                } else {
                    $id_query .= " ORDER BY $domains_table.name";
                }

                $id_query .= " LIMIT " . intval($rowamount) . " OFFSET " . intval($rowstart);
            }

            $query = "SELECT $domains_table.id,
                            $domains_table.name,
                            $domains_table.type,
                            COUNT(DISTINCT $records_table.id) AS count_records,
                            users.username,
                            users.fullname
                            " . ($pdnssec_use ? ", MAX(CASE WHEN $cryptokeys_table.id IS NOT NULL OR $domainmetadata_table.id IS NOT NULL THEN 1 ELSE 0 END) AS secured" : "") . "
                            " . ($iface_zone_comments ? ", zones.comment" : "") . "
                        FROM $domains_table
                        INNER JOIN (" . $id_query . ") AS limited_domains ON $domains_table.id = limited_domains.id
                        LEFT JOIN zones ON $domains_table.id=zones.domain_id
                        LEFT JOIN $records_table ON $records_table.domain_id=$domains_table.id AND $records_table.type IS NOT NULL
                        LEFT JOIN users ON users.id=zones.owner";

            if ($pdnssec_use) {
                $query .= " LEFT JOIN $cryptokeys_table ON $domains_table.id = $cryptokeys_table.domain_id AND $cryptokeys_table.active
                            LEFT JOIN $domainmetadata_table ON $domains_table.id = $domainmetadata_table.domain_id AND $domainmetadata_table.kind = 'PRESIGNED'";
            }

            $query .= " GROUP BY $domains_table.name, $domains_table.id, $domains_table.type, users.username, users.fullname
                        " . ($iface_zone_comments ? ", zones.comment" : "") . "
                        ORDER BY " . $sql_sortby;

            if (!empty($params)) {
                $stmt = $this->db->prepare($query);
                $stmt->execute($params);
                $result = $stmt;
            } else {
                $result = $this->db->query($query);
            }

            DbCompat::restoreSqlMode($this->db, $db_type, $originalSqlMode);
        } else {
            $originalSqlMode = DbCompat::handleSqlMode($this->db, $db_type);

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

            if (!empty($params)) {
                $stmt = $this->db->prepare($query);
                $stmt->execute($params);
                $result = $stmt;
            } else {
                $result = $this->db->query($query);
            }

            DbCompat::restoreSqlMode($this->db, $db_type, $originalSqlMode);
        }

        $ret = array();
        while ($r = $result->fetch()) {
            $domainName = $r["name"];
            $utf8Name = DnsIdnService::toUtf8($domainName);

            $ret[$domainName]["id"] = $r["id"];
            $ret[$domainName]["name"] = $domainName;
            $ret[$domainName]["utf8_name"] = $utf8Name;
            $ret[$domainName]["type"] = $r["type"];
            $ret[$domainName]["count_records"] = $r["count_records"];
            $ret[$domainName]["comment"] = $r["comment"] ?? '';

            if ($r["username"] !== null) {
                $ret[$domainName]["owners"][] = $r["username"];
                $ret[$domainName]["full_names"][] = $r["fullname"] ?: '';
                $ret[$domainName]["users"][] = $r["username"];
            } else {
                if (!isset($ret[$domainName]["owners"])) {
                    $ret[$domainName]["owners"] = [];
                }
                if (!isset($ret[$domainName]["full_names"])) {
                    $ret[$domainName]["full_names"] = [];
                }
                if (!isset($ret[$domainName]["users"])) {
                    $ret[$domainName]["users"] = [];
                }
            }

            if ($pdnssec_use) {
                $ret[$domainName]["secured"] = $r["secured"];
            }

            if ($iface_zonelist_serial) {
                $recordRepository = new SqlRecordRepository($this->db, $this->config);
                $ret[$domainName]["serial"] = $recordRepository->getSerialByZid($r["id"]);
            }

            if ($iface_zonelist_template) {
                $ret[$domainName]["template"] = ZoneTemplate::getZoneTemplName($this->db, $r["id"]);
            }
        }

        return $ret;
    }

    public function getZoneInfoFromId(int $zid): array
    {
        $perm_view = Permission::getViewPermission($this->db);

        if ($perm_view == "none") {
            $this->messageService->addSystemError(_("You do not have the permission to view this zone."));
            return [];
        }

        [$domains_table, $records_table] = $this->tableNameService->getTables(
            PdnsTable::DOMAINS,
            PdnsTable::RECORDS
        );

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

    public function getZoneInfoFromIds(array $zones): array
    {
        $zone_infos = array();
        foreach ($zones as $zone) {
            $zone_info = $this->getZoneInfoFromId($zone);
            $zone_infos[] = $zone_info;
        }
        return $zone_infos;
    }

    public function getBestMatchingZoneIdFromName(string $domain): int
    {
        $match = 72;
        $found_domain_id = -1;

        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->prepare("SELECT name, id FROM $domains_table
                   WHERE name like :pattern
                   ORDER BY length(name) DESC");
        $stmt->execute([':pattern' => '%.arpa']);
        $response = $stmt;
        if ($response) {
            while ($r = $response->fetch()) {
                $pos = stripos($domain, $r["name"]);
                if ($pos !== false) {
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
