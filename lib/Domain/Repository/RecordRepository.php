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
use Poweradmin\Domain\Model\Constants;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Utility\SortHelper;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;

/**
 * Repository class for DNS record operations
 */
class RecordRepository implements RecordRepositoryInterface
{

    private PDOCommon $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private TableNameService $tableNameService;

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
        $this->tableNameService = new TableNameService($config);
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
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

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
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT COUNT(id) FROM $records_table WHERE domain_id = :zone_id AND type IS NOT NULL AND type != ''");
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
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

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
     * @return array|null array of record detail, or null if nothing found
     */
    public function getRecordFromId(int $id): ?array
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT * FROM $records_table WHERE id = :id AND type IS NOT NULL AND type != ''");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        if ($result) {
            if ($result["content"] == "") {
                return null;
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
            return null;
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
     * @return array array of record details (empty array if nothing found)
     */
    public function getRecordsFromDomainId(string $db_type, int $id, int $rowstart = 0, int $rowamount = Constants::DEFAULT_MAX_ROWS, string $sortby = 'name', string $sortDirection = 'ASC', bool $fetchComments = false): array
    {
        if (!is_numeric($id)) {
            return [];
        }

        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $comments_table = $this->tableNameService->getTable(PdnsTable::COMMENTS);
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        if ($sortby == 'name') {
            $sortby = "$records_table.name";
        }
        $sql_sortby = $sortby == "$records_table.name" ? SortHelper::getRecordSortOrder($records_table, $db_type, $sortDirection) : $sortby . " " . $sortDirection;
        if ($sortby == "$records_table.name" and $sortDirection == 'ASC') {
            // Order: SOA first, then NS, then apex records (@), then everything else
            $sql_sortby = "$records_table.type = 'SOA' DESC, $records_table.type = 'NS' DESC, " .
                         "$records_table.name = (SELECT name FROM $domains_table WHERE id = :domain_id_apex) DESC, " .
                         $sql_sortby;
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
            AND $records_table.type IS NOT NULL AND $records_table.type != ''
            ORDER BY " . $sql_sortby;

        if ($rowamount < Constants::DEFAULT_MAX_ROWS) {
            $query .= " LIMIT " . $rowamount;
            if ($rowstart > 0) {
                $query .= " OFFSET " . $rowstart;
            }
        }

        $stmt = $this->db->prepare($query);
        $params = [':domain_id' => $id];
        if ($sortby == "$records_table.name" and $sortDirection == 'ASC') {
            $params[':domain_id_apex'] = $id;
        }
        $stmt->execute($params);
        $records = $stmt;

        if ($records) {
            $result = $records->fetchAll();
            return $result ?: [];
        }

        return [];
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
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

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
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

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
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

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
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

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
     * Check if any PTR record exists for a given reverse domain name
     *
     * @param int $domain_id Domain ID
     * @param string $name Reverse domain name (e.g., "1.1.168.192.in-addr.arpa")
     *
     * @return bool True if any PTR record exists for this name
     */
    public function hasPtrRecord(int $domain_id, string $name): bool
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM $records_table 
                  WHERE domain_id = :domain_id 
                  AND name = :name 
                  AND type = 'PTR'");
        $stmt->execute([
            ':domain_id' => $domain_id,
            ':name' => $name
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
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT content FROM $records_table WHERE type = :type AND domain_id = :domain_id");
        $stmt->execute([
            ':type' => 'SOA',
            ':domain_id' => $zid
        ]);
        $rr_soa = $stmt->fetchColumn();
        $rr_soa_fields = explode(" ", $rr_soa);
        return $rr_soa_fields[2] ?? '';
    }

    /**
     * Get filtered records from a domain with search capabilities
     *
     * @param int $zone_id The zone ID
     * @param int $row_start Starting row for pagination
     * @param int $row_amount Number of rows per page
     * @param string $sort_by Column to sort by
     * @param string $sort_direction Sort direction (ASC or DESC)
     * @param bool $include_comments Whether to include comments
     * @param string $search_term Optional search term to filter by name or content
     * @param string $type_filter Optional record type filter
     * @param string $content_filter Optional content filter
     * @return array Array of filtered records
     */
    public function getFilteredRecords(
        int $zone_id,
        int $row_start,
        int $row_amount,
        string $sort_by,
        string $sort_direction,
        bool $include_comments,
        string $search_term = '',
        string $type_filter = '',
        string $content_filter = ''
    ): array {
        // Validate sort parameters
        $allowedSortColumns = ['id', 'name', 'type', 'content', 'ttl', 'prio', 'disabled'];
        $sort_by = $this->tableNameService->validateOrderBy($sort_by, $allowedSortColumns);
        $sort_direction = $this->tableNameService->validateDirection($sort_direction);

        // Validate limit/offset parameters
        $row_amount = $this->tableNameService->validateLimit($row_amount);
        $row_start = $this->tableNameService->validateOffset($row_start);

        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $comments_table = $this->tableNameService->getTable(PdnsTable::COMMENTS);

        // Prepare query parameters
        $params = [':zone_id' => $zone_id];

        // Apply search term to both name and content
        $search_condition = '';
        if (!empty($search_term)) {
            // If search term doesn't already have wildcards, add them
            if (strpos($search_term, '%') === false) {
                $search_term = '%' . $search_term . '%';
            }
            $search_condition = " AND ($records_table.name LIKE :search_term1 OR $records_table.content LIKE :search_term2)";
            $params[':search_term1'] = $search_term;
            $params[':search_term2'] = $search_term;
        }

        // Apply type filter
        $type_condition = '';
        if (!empty($type_filter)) {
            $type_condition = " AND $records_table.type = :type_filter";
            $params[':type_filter'] = $type_filter;
        }

        // Apply content filter
        $content_condition = '';
        if (!empty($content_filter)) {
            // If content filter doesn't already have wildcards, add them
            if (strpos($content_filter, '%') === false) {
                $content_filter = '%' . $content_filter . '%';
            }
            $content_condition = " AND $records_table.content LIKE :content_filter";
            $params[':content_filter'] = $content_filter;
        }

        // Base query
        $query = "SELECT $records_table.id, $records_table.domain_id, $records_table.name, $records_table.type, 
                 $records_table.content, $records_table.ttl, $records_table.prio, $records_table.disabled";

        // Add comment column if needed
        if ($include_comments) {
            $query .= ", c.comment";
        }

        // From and joins
        $query .= " FROM $records_table";
        if ($include_comments) {
            $query .= " LEFT JOIN $comments_table c ON $records_table.domain_id = c.domain_id 
                      AND $records_table.name = c.name AND $records_table.type = c.type";
        }

        // Where clause - filter out records with NULL or empty type
        $query .= " WHERE $records_table.domain_id = :zone_id AND $records_table.type IS NOT NULL AND $records_table.type != ''" .
                 $search_condition . $type_condition . $content_condition;

        // Sorting and limits
        $query .= " ORDER BY $sort_by $sort_direction LIMIT :row_amount OFFSET :row_start";
        $params[':row_amount'] = $row_amount;
        $params[':row_start'] = $row_start;

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $records = [];

        while ($record = $stmt->fetch()) {
            $records[] = $record;
        }

        return $records;
    }

    /**
     * Get count of filtered records
     *
     * @param int $zone_id The zone ID
     * @param bool $include_comments Whether to include comments in the search
     * @param string $search_term Optional search term to filter by name or content
     * @param string $type_filter Optional record type filter
     * @param string $content_filter Optional content filter
     * @return int Number of filtered records
     */
    public function getFilteredRecordCount(
        int $zone_id,
        bool $include_comments,
        string $search_term = '',
        string $type_filter = '',
        string $content_filter = ''
    ): int {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        // Prepare query parameters
        $params = [':zone_id' => $zone_id];

        // Apply search term to both name and content
        $search_condition = '';
        if (!empty($search_term)) {
            // If search term doesn't already have wildcards, add them
            if (strpos($search_term, '%') === false) {
                $search_term = '%' . $search_term . '%';
            }
            $search_condition = " AND ($records_table.name LIKE :search_term1 OR $records_table.content LIKE :search_term2)";
            $params[':search_term1'] = $search_term;
            $params[':search_term2'] = $search_term;
        }

        // Apply type filter
        $type_condition = '';
        if (!empty($type_filter)) {
            $type_condition = " AND $records_table.type = :type_filter";
            $params[':type_filter'] = $type_filter;
        }

        // Apply content filter
        $content_condition = '';
        if (!empty($content_filter)) {
            // If content filter doesn't already have wildcards, add them
            if (strpos($content_filter, '%') === false) {
                $content_filter = '%' . $content_filter . '%';
            }
            $content_condition = " AND $records_table.content LIKE :content_filter";
            $params[':content_filter'] = $content_filter;
        }

        // Create the query - filter out records with NULL or empty type
        $query = "SELECT COUNT(*) FROM $records_table WHERE domain_id = :zone_id AND type IS NOT NULL AND type != ''";

        // Add filter conditions
        $query .= $search_condition . $type_condition . $content_condition;

        // Execute and return result
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get the ID of a newly created record
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @return int|null Record ID or null if not found
     */
    public function getNewRecordId(int $domainId, string $name, string $type, string $content): ?int
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $query = "SELECT id FROM $records_table
                 WHERE domain_id = :domain_id
                 AND name = :name
                 AND type = :type
                 AND content = :content
                 ORDER BY id DESC LIMIT 1";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => strtolower($name),
            ':type' => $type,
            ':content' => $content
        ]);

        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    }

    public function getRecordsByDomainId(int $domainId, ?string $recordType = null): array
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $query = "SELECT id, domain_id, name, type, content, ttl, prio, disabled, ordername, auth
                  FROM $records_table
                  WHERE domain_id = :domain_id";

        $params = [':domain_id' => $domainId];

        if ($recordType !== null) {
            $query .= " AND type = :type";
            $params[':type'] = $recordType;
        }

        $query .= " ORDER BY type, name";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRecordById(int $recordId): ?array
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $query = "SELECT id, domain_id, name, type, content, ttl, prio, disabled, ordername, auth
                  FROM $records_table
                  WHERE id = :id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $recordId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
