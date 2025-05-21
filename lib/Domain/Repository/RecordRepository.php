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

use PDO;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Utility\SortHelper;

/**
 * Repository class for DNS record operations
 */
class RecordRepository implements RecordRepositoryInterface
{
    private const DEFAULT_MAX_ROWS = 9999;

    private PDOCommon $db;
    private ConfigurationManager $config;
    private MessageService $messageService;

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
    }

    /**
     * Get Zone ID from Record ID
     *
     * @param int $rid Record ID
     *
     * @return int Zone ID
     */
    public function getZoneIdFromRecordId(int $rid): int
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $stmt = $this->db->prepare("SELECT domain_id FROM $records_table WHERE id = :id");
        $stmt->execute([':id' => $rid]);
        return $stmt->fetchColumn() ?: 0;
    }

    /**
     * Count Zone Records for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return int Record count
     */
    public function countZoneRecords(int $zone_id): int
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $stmt = $this->db->prepare("SELECT COUNT(id) FROM $records_table WHERE domain_id = :zone_id AND type IS NOT NULL");
        $stmt->execute([':zone_id' => $zone_id]);
        return $stmt->fetchColumn() ?: 0;
    }

    /**
     * Get record details from Record ID
     *
     * @param int $rid Record ID
     *
     * @return array array of record details [rid,zid,name,type,content,ttl,prio]
     */
    public function getRecordDetailsFromRecordId(int $rid): array
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $stmt = $this->db->prepare("SELECT id AS rid, domain_id AS zid, name, type, content, ttl, prio FROM $records_table WHERE id = :id");
        $stmt->execute([':id' => $rid]);
        return $stmt->fetch() ?: [];
    }

    /**
     * Get a Record from a Record ID
     *
     * Retrieve all fields of the record and send it back to the function caller.
     *
     * @param int $id Record ID
     * @return int|array array of record detail, or -1 if nothing found
     */
    public function getRecordFromId(int $id): int|array
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $stmt = $this->db->prepare("SELECT * FROM $records_table WHERE id = :id AND type IS NOT NULL");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        if ($result) {
            if ($result["type"] == "" || $result["content"] == "") {
                return -1;
            }

            return array(
                "id" => $result["id"],
                "domain_id" => $result["domain_id"],
                "name" => $result["name"],
                "type" => $result["type"],
                "content" => $result["content"],
                "ttl" => $result["ttl"],
                "prio" => $result["prio"],
                "disabled" => $result["disabled"],
                "ordername" => $result["ordername"],
                "auth" => $result["auth"],
            );
        } else {
            return -1;
        }
    }

    /**
     * Get all records from a domain id.
     *
     * Retrieve all fields of the records and send it back to the function caller.
     *
     * @param string $db_type Database type
     * @param int $id Domain ID
     * @param int $rowstart Starting row [default=0]
     * @param int $rowamount Number of rows to return in this query [default=9999]
     * @param string $sortby Column to sort by [default='name']
     * @param string $sortDirection Sort direction [default='ASC']
     * @param bool $fetchComments Whether to fetch record comments [default=false]
     *
     * @return int|array array of record detail, or -1 if nothing found
     */
    public function getRecordsFromDomainId(string $db_type, int $id, int $rowstart = 0, int $rowamount = self::DEFAULT_MAX_ROWS, string $sortby = 'name', string $sortDirection = 'ASC', bool $fetchComments = false): array|int
    {
        if (!is_numeric($id)) {
            $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s'), "getRecordsFromDomainId"));

            return -1;
        }

        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';
        $comments_table = $pdns_db_name ? $pdns_db_name . '.comments' : 'comments';

        if ($sortby == 'name') {
            $sortby = "$records_table.name";
        }
        $sql_sortby = $sortby == "$records_table.name" ? SortHelper::getRecordSortOrder($records_table, $db_type, $sortDirection) : $sortby . " " . $sortDirection;
        if ($sortby == "$records_table.name" and $sortDirection == 'ASC') {
            $sql_sortby = "$records_table.type = 'SOA' DESC, $records_table.type = 'NS' DESC, " . $sql_sortby;
        }

        $query = "SELECT $records_table.*,
            " . ($fetchComments ? "(
                SELECT comment
                FROM $comments_table
                WHERE $records_table.domain_id = $comments_table.domain_id
                AND $records_table.name = $comments_table.name
                AND $records_table.type = $comments_table.type
                LIMIT 1
            )" : "NULL") . " AS comment
            FROM $records_table
            WHERE $records_table.domain_id = :domain_id
            AND $records_table.type IS NOT NULL
            ORDER BY " . $sql_sortby;

        if ($rowamount < self::DEFAULT_MAX_ROWS) {
            $query .= " LIMIT " . $rowamount;
            if ($rowstart > 0) {
                $query .= " OFFSET " . $rowstart;
            }
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute([':domain_id' => $id]);
        $records = $stmt;

        if ($records) {
            $result = $records->fetchAll();
        } else {
            return -1;
        }

        return $result;
    }

    /**
     * Record ID to Domain ID
     *
     * Gets the id of the domain by a given record id
     *
     * @param int $id Record ID
     * @return int Domain ID of record
     */
    public function recidToDomid(int $id): int
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $stmt = $this->db->prepare("SELECT domain_id FROM $records_table WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch();
        return $r["domain_id"] ?? 0;
    }

    /**
     * Check if record name exists
     *
     * @param string $name Record name
     *
     * @return boolean true on success, false on failure
     */
    public function recordNameExists(string $name): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $stmt = $this->db->prepare("SELECT COUNT(id) FROM $records_table WHERE name = :name");
        $stmt->execute([':name' => $name]);
        $count = $stmt->fetchColumn();
        return $count > 0;
    }

    /**
     * Check if record has similar records with same name and type
     *
     * @param int $domain_id Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param int $record_id Current record ID to exclude from check
     * @return bool True if similar records found, false otherwise
     */
    public function hasSimilarRecords(int $domain_id, string $name, string $type, int $record_id): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT COUNT(*) FROM $records_table
              WHERE domain_id = :domain_id AND name = :name AND type = :type AND id != :record_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':domain_id' => $domain_id,
            ':name' => $name,
            ':type' => $type,
            ':record_id' => $record_id
        ]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Check if a record with the given parameters already exists
     *
     * @param int $domain_id Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @return bool True if record exists, false otherwise
     */
    public function recordExists(int $domain_id, string $name, string $type, string $content): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM $records_table 
                  WHERE domain_id = :domain_id 
                  AND name = :name 
                  AND type = :type 
                  AND content = :content");
        $stmt->execute([
            ':domain_id' => $domain_id,
            ':name' => $name,
            ':type' => $type,
            ':content' => $content
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get Serial for Zone ID
     *
     * @param int $zid Zone ID
     *
     * @return string Serial Number or empty string if not found
     */
    public function getSerialByZid(int $zid): string
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $stmt = $this->db->prepare("SELECT content FROM $records_table WHERE type = :type AND domain_id = :domain_id");
        $stmt->execute([
            ':type' => 'SOA',
            ':domain_id' => $zid
        ]);
        $rr_soa = $stmt->fetchColumn();
        $rr_soa_fields = explode(" ", $rr_soa);
        return $rr_soa_fields[2] ?? '';
    }
}
