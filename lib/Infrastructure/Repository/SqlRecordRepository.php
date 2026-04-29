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
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Utility\SortHelper;

/**
 * SQL-backend record repository.
 * Queries PowerDNS tables directly via PDO.
 */
class SqlRecordRepository implements RecordRepositoryInterface
{
    use RecordCommentEnrichmentTrait;

    private PDO $db;
    private TableNameService $tableNameService;

    public function __construct(PDO $db, ConfigurationInterface $config)
    {
        $this->db = $db;
        $this->tableNameService = new TableNameService($config);
    }

    public function getZoneIdFromRecordId(int|string $rid): int
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT domain_id FROM $records_table WHERE id = :id");
        $stmt->execute([':id' => $rid]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function countZoneRecords(int $zone_id): int
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT COUNT(id) FROM $records_table WHERE domain_id = :zone_id AND type IS NOT NULL AND type != ''");
        $stmt->execute([':zone_id' => $zone_id]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function getRecordDetailsFromRecordId(int|string $rid): array
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT id AS rid, domain_id AS zid, name, type, content, ttl, prio, disabled FROM $records_table WHERE id = :id");
        $stmt->execute([':id' => $rid]);
        return $stmt->fetch() ?: [];
    }

    public function getRecordFromId(int|string $id): ?array
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

    public function getRecordsFromDomainId(string $db_type, int $id, int $rowstart = 0, int $rowamount = Constants::DEFAULT_MAX_ROWS, string $sortby = 'name', string $sortDirection = 'ASC', bool $fetchComments = false): array
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $comments_table = $this->tableNameService->getTable(PdnsTable::COMMENTS);
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        if ($sortby == 'name') {
            $sortby = "$records_table.name";
        }
        $sql_sortby = $sortby == "$records_table.name" ? SortHelper::getRecordSortOrder($records_table, $db_type, $sortDirection) : $sortby . " " . $sortDirection;
        if ($sortby == "$records_table.name" and $sortDirection == 'ASC') {
            $sql_sortby = "$records_table.type = 'SOA' DESC, $records_table.type = 'NS' DESC, " .
                         "$records_table.name = (SELECT name FROM $domains_table WHERE id = :domain_id_apex) DESC, " .
                         $sql_sortby;
        }

        $links_table = 'record_comment_links';
        $castId = DbCompat::castToString($db_type, "$records_table.id");
        $query = "SELECT $records_table.*,
            " . ($fetchComments ? "COALESCE(
                (
                    SELECT c.comment
                    FROM $links_table rcl
                    JOIN $comments_table c ON c.id = rcl.comment_id
                    WHERE rcl.record_id = $castId
                    LIMIT 1
                ),
                (
                    SELECT c.comment
                    FROM $comments_table c
                    WHERE c.domain_id = $records_table.domain_id
                      AND c.name = $records_table.name
                      AND c.type = $records_table.type
                      AND NOT EXISTS (
                          SELECT 1 FROM $links_table rcl2
                          WHERE rcl2.comment_id = c.id
                      )
                    LIMIT 1
                )
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

    public function recidToDomid(int|string $id): int
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT domain_id FROM $records_table WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch();
        return $r["domain_id"] ?? 0;
    }

    public function recordNameExists(string $name): bool
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT COUNT(id) FROM $records_table WHERE name = :name");
        $stmt->execute([':name' => $name]);
        $count = $stmt->fetchColumn();
        return $count > 0;
    }

    public function hasNonDelegationRecords(string $name): bool
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare(
            "SELECT COUNT(id) FROM $records_table
             WHERE name = :name
             AND type NOT IN ('NS', 'DS')"
        );
        $stmt->execute([':name' => $name]);
        $count = $stmt->fetchColumn();
        return $count > 0;
    }

    public function hasSimilarRecords(int $domain_id, string $name, string $type, int|string $record_id): bool
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

    public function getRecordId(int $domain_id, string $name, string $type, string $content, ?int $prio = null, ?int $ttl = null): int|string|null
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $query = "SELECT id FROM $records_table
                  WHERE domain_id = :domain_id
                  AND name = :name
                  AND type = :type
                  AND content = :content";

        $params = [
            ':domain_id' => $domain_id,
            ':name' => $name,
            ':type' => $type,
            ':content' => $content
        ];

        if ($prio !== null) {
            $query .= " AND prio = :prio";
            $params[':prio'] = $prio;
        }

        if ($ttl !== null) {
            $query .= " AND ttl = :ttl";
            $params[':ttl'] = $ttl;
        }

        $query .= " ORDER BY id DESC LIMIT 1";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetchColumn();
        return $result !== false ? (int)$result : null;
    }

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

    public function getSerialsByZoneIds(array $zoneIds): array
    {
        if (empty($zoneIds)) {
            return [];
        }

        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $placeholders = implode(',', array_fill(0, count($zoneIds), '?'));

        $stmt = $this->db->prepare("SELECT domain_id, content FROM $records_table WHERE type = 'SOA' AND domain_id IN ($placeholders)");

        $paramIndex = 1;
        foreach ($zoneIds as $zoneId) {
            $stmt->bindValue($paramIndex, $zoneId, PDO::PARAM_INT);
            $paramIndex++;
        }
        $stmt->execute();

        $serials = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rr_soa_fields = explode(" ", $row['content']);
            $serials[$row['domain_id']] = $rr_soa_fields[2] ?? '';
        }

        return $serials;
    }

    public function getRecordsByDomainId(int $domainId, ?string $recordType = null): array
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $query = "SELECT id, domain_id, name, type, content, ttl, prio, disabled, ordername, auth
                  FROM $records_table
                  WHERE domain_id = :domain_id
                  AND type IS NOT NULL AND type != ''";

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

        $sort_by_qualified = $records_table . '.' . $sort_by;

        $params = [':zone_id' => $zone_id];

        $search_condition = '';
        if (!empty($search_term)) {
            if (strpos($search_term, '%') === false) {
                $search_term = '%' . $search_term . '%';
            }
            $search_condition = " AND ($records_table.name LIKE :search_term1 OR $records_table.content LIKE :search_term2)";
            $params[':search_term1'] = $search_term;
            $params[':search_term2'] = $search_term;
        }

        $type_condition = '';
        if (!empty($type_filter)) {
            $type_condition = " AND $records_table.type = :type_filter";
            $params[':type_filter'] = $type_filter;
        }

        $content_condition = '';
        if (!empty($content_filter)) {
            if (strpos($content_filter, '%') === false) {
                $content_filter = '%' . $content_filter . '%';
            }
            $content_condition = " AND $records_table.content LIKE :content_filter";
            $params[':content_filter'] = $content_filter;
        }

        $links_table = 'record_comment_links';
        $dbType = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $castId = DbCompat::castToString($dbType, "$records_table.id");
        $query = "SELECT $records_table.id, $records_table.domain_id, $records_table.name, $records_table.type,
                 $records_table.content, $records_table.ttl, $records_table.prio, $records_table.disabled, $records_table.auth";

        if ($include_comments) {
            $query .= ", COALESCE(
                (
                    SELECT c.comment
                    FROM $links_table rcl
                    JOIN $comments_table c ON c.id = rcl.comment_id
                    WHERE rcl.record_id = $castId
                    LIMIT 1
                ),
                (
                    SELECT c.comment
                    FROM $comments_table c
                    WHERE c.domain_id = $records_table.domain_id
                      AND c.name = $records_table.name
                      AND c.type = $records_table.type
                      AND NOT EXISTS (
                          SELECT 1 FROM $links_table rcl2
                          WHERE rcl2.comment_id = c.id
                      )
                    LIMIT 1
                )
            ) AS comment";
        }

        $query .= " FROM $records_table";

        $query .= " WHERE $records_table.domain_id = :zone_id AND $records_table.type IS NOT NULL AND $records_table.type != ''" .
                 $search_condition . $type_condition . $content_condition;

        $query .= " ORDER BY $sort_by_qualified $sort_direction";

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $query .= " LIMIT $row_amount OFFSET $row_start";
        } else {
            $query .= " LIMIT :row_amount OFFSET :row_start";
        }

        $stmt = $this->db->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        if ($driver !== 'sqlite') {
            $stmt->bindValue(':row_amount', $row_amount, PDO::PARAM_INT);
            $stmt->bindValue(':row_start', $row_start, PDO::PARAM_INT);
        }

        $stmt->execute();
        $records = [];

        while ($record = $stmt->fetch()) {
            $records[] = $record;
        }

        return $records;
    }

    public function getFilteredRecordCount(
        int $zone_id,
        bool $include_comments,
        string $search_term = '',
        string $type_filter = '',
        string $content_filter = ''
    ): int {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $params = [':zone_id' => $zone_id];

        $search_condition = '';
        if (!empty($search_term)) {
            if (strpos($search_term, '%') === false) {
                $search_term = '%' . $search_term . '%';
            }
            $search_condition = " AND ($records_table.name LIKE :search_term1 OR $records_table.content LIKE :search_term2)";
            $params[':search_term1'] = $search_term;
            $params[':search_term2'] = $search_term;
        }

        $type_condition = '';
        if (!empty($type_filter)) {
            $type_condition = " AND $records_table.type = :type_filter";
            $params[':type_filter'] = $type_filter;
        }

        $content_condition = '';
        if (!empty($content_filter)) {
            if (strpos($content_filter, '%') === false) {
                $content_filter = '%' . $content_filter . '%';
            }
            $content_condition = " AND $records_table.content LIKE :content_filter";
            $params[':content_filter'] = $content_filter;
        }

        $query = "SELECT COUNT(*) FROM $records_table WHERE domain_id = :zone_id AND type IS NOT NULL AND type != ''";

        $query .= $search_condition . $type_condition . $content_condition;

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function getNewRecordId(int $domainId, string $name, string $type, string $content): int|string|null
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

    public function getRecordById(int|string $recordId): ?array
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

    public function getRRSetRecords(int $domainId, string $name, string $type): array
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $name = strtolower($name);

        $query = "SELECT id, domain_id, name, type, content, ttl, prio, disabled, ordername, auth
                  FROM $records_table
                  WHERE domain_id = :domain_id
                    AND name = :name
                    AND type = :type
                  ORDER BY content";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
