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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Utility\NaturalSorting;
use Poweradmin\Infrastructure\Utility\ReverseDomainNaturalSorting;
use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;

class DbZoneRepository implements ZoneRepositoryInterface
{
    private object $db;
    private string $db_type;
    private ?string $pdns_db_name;
    private NaturalSorting $naturalSorting;
    private ReverseDomainNaturalSorting $reverseDomainNaturalSorting;
    private ReverseZoneSorting $reverseZoneSorting;
    private object $config;
    private TableNameService $tableNameService;

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->db_type = $config->get('database', 'type');
        $this->pdns_db_name = $config->get('database', 'pdns_db_name');
        $this->naturalSorting = new NaturalSorting();
        $this->reverseDomainNaturalSorting = new ReverseDomainNaturalSorting();
        $this->reverseZoneSorting = new ReverseZoneSorting();
        $this->tableNameService = new TableNameService($config);
    }


    public function getDistinctStartingLetters(int $userId, bool $viewOthers): array
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "SELECT DISTINCT LOWER(" . DbCompat::substr($this->db_type) . "($domains_table.name, 1, 1)) AS letter FROM $domains_table";

        if (!$viewOthers) {
            $query .= " LEFT JOIN zones ON $domains_table.id = zones.domain_id";
            $query .= " WHERE zones.owner = :userId";
        }

        $query .= " ORDER BY letter";

        $stmt = $this->db->prepare($query);

        if (!$viewOthers) {
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        }

        $stmt->execute();

        $letters = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        return array_filter($letters, function ($letter) {
            return ctype_alpha($letter) || is_numeric($letter);
        });
    }

    /**
     * Get reverse zones with efficient database-level filtering and pagination
     *
     * @param string $permType Permission type ('all', 'own')
     * @param int $userId User ID (used when permType is 'own')
     * @param string $reverseType Filter by reverse zone type ('all', 'ipv4', 'ipv6')
     * @param int $offset Pagination offset
     * @param int $limit Maximum number of records to return
     * @param string $sortBy Column to sort by
     * @param string $sortDirection Sort direction ('ASC' or 'DESC')
     * @param bool $countOnly If true, returns only the count of matching zones
     * @return array|int Array of reverse zones or count if countOnly is true
     */
    public function getReverseZones(
        string $permType,
        int $userId,
        string $reverseType = 'all',
        int $offset = 0,
        int $limit = 25,
        string $sortBy = 'name',
        string $sortDirection = 'ASC',
        bool $countOnly = false
    ) {
        // Validate sort parameters
        $allowedSortColumns = ['name', 'owner', 'count_records', 'type'];
        $sortBy = $this->tableNameService->validateOrderBy($sortBy, $allowedSortColumns);
        $sortDirection = $this->tableNameService->validateDirection($sortDirection);

        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $cryptokeys_table = $this->tableNameService->getTable(PdnsTable::CRYPTOKEYS);
        $domainmetadata_table = $this->tableNameService->getTable(PdnsTable::DOMAINMETADATA);

        // Determine what fields to select
        if ($countOnly) {
            // Use a subquery for accurate counting without GROUP BY complications
            $selectFields = "COUNT(*) as count";

            // Initialize params array for count query
            $params = [];

            // For count queries, use a simpler join structure
            $query = "SELECT $selectFields 
                     FROM (
                         SELECT DISTINCT $domains_table.id
                         FROM $domains_table
                         LEFT JOIN zones ON $domains_table.id = zones.domain_id
                         WHERE 1=1";

            if ($permType == 'own') {
                $query .= " AND zones.owner = :userId";
                $params[':userId'] = $userId;
            }

            // Add reverse zone type filter
            $query .= " AND (";
            if ($reverseType == 'all' || $reverseType == 'ipv4') {
                $query .= "$domains_table.name LIKE '%.in-addr.arpa'";
                if ($reverseType == 'all') {
                    $query .= " OR ";
                }
            }

            if ($reverseType == 'all' || $reverseType == 'ipv6') {
                $query .= "$domains_table.name LIKE '%.ip6.arpa'";
            }
            $query .= ")";

            $query .= ") AS distinct_domains";

            // Execute count query
            $stmt = $this->db->prepare($query);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();

            return (int)$stmt->fetchColumn();
        } else {
            $selectFields = "$domains_table.id, 
                           $domains_table.name, 
                           $domains_table.type, 
                           COUNT($records_table.id) AS count_records, 
                           users.username, 
                           users.fullname,
                           COUNT($cryptokeys_table.id) > 0 OR COUNT($domainmetadata_table.id) > 0 AS secured,
                           zones.comment";
        }

        // Build the base query
        $query = "SELECT $selectFields
                 FROM $domains_table
                 LEFT JOIN zones ON $domains_table.id = zones.domain_id
                 LEFT JOIN $records_table ON $records_table.domain_id = $domains_table.id AND $records_table.type IS NOT NULL
                 LEFT JOIN users ON users.id = zones.owner
                 LEFT JOIN $cryptokeys_table ON $domains_table.id = $cryptokeys_table.domain_id AND $cryptokeys_table.active
                 LEFT JOIN $domainmetadata_table ON $domains_table.id = $domainmetadata_table.domain_id AND $domainmetadata_table.kind = 'PRESIGNED'
                 WHERE 1=1";

        // Add permission filter
        $params = [];
        if ($permType == 'own') {
            $query .= " AND zones.owner = :userId";
            $params[':userId'] = $userId;
        }

        // Add reverse zone type filter at database level
        $query .= " AND (";
        if ($reverseType == 'all' || $reverseType == 'ipv4') {
            $query .= "$domains_table.name LIKE '%.in-addr.arpa'";
            if ($reverseType == 'all') {
                $query .= " OR ";
            }
        }

        if ($reverseType == 'all' || $reverseType == 'ipv6') {
            $query .= "$domains_table.name LIKE '%.ip6.arpa'";
        }
        $query .= ")";

        // GROUP BY only needed for non-count queries -
        // count queries are already handled and returned above

        // Group by needed fields
        $query .= " GROUP BY $domains_table.name, $domains_table.id, $domains_table.type, users.username, users.fullname, zones.comment";

        // Add sorting
        if ($sortBy == 'owner') {
            $sortBy = 'users.username';
        } elseif ($sortBy == 'count_records') {
            $sortBy = "COUNT($records_table.id)";
        } else {
            $sortBy = "$domains_table.$sortBy";
        }

        // Get sorting method from configuration (natural by default)
        $sortType = $this->config->get('interface', 'reverse_zone_sort', 'natural');

        $query .= " ORDER BY " . ($sortBy == "$domains_table.name" ?
            $this->reverseZoneSorting->getSortOrder("$domains_table.name", $this->db_type, $sortDirection, $sortType) :
            "$sortBy $sortDirection");

        // Add limit and offset for pagination
        $query .= " LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        // Execute query
        $stmt = $this->db->prepare($query);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        if ($countOnly) {
            return (int)$stmt->fetchColumn();
        }

        // Process results
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $zones = [];

        foreach ($results as $row) {
            $name = $row['name'];
            if (!isset($zones[$name])) {
                $zones[$name] = [
                    'id' => $row['id'],
                    'name' => $name,
                    'utf8_name' => DnsIdnService::toUtf8($name),
                    'type' => $row['type'],
                    'count_records' => $row['count_records'],
                    'comment' => $row['comment'] ?? '',
                    'secured' => $row['secured'],
                    'owners' => [],
                    'full_names' => [],
                    'users' => []
                ];

                // Add serial number if configured
                if ($this->config->get('interface', 'display_serial_in_zone_list')) {
                    // Create RecordRepository to get the serial
                    $recordRepository = new RecordRepository($this->db, $this->config);
                    $zones[$name]['serial'] = $recordRepository->getSerialByZid($row['id']);
                }
            }

            $zones[$name]['owners'][] = $row['username'];
            $zones[$name]['full_names'][] = $row['fullname'] ?: '';
            $zones[$name]['users'][] = $row['username'];
        }

        return $zones;
    }

    /**
     * Get domain name by ID
     *
     * @param int $zoneId The zone ID
     * @return string|null The domain name or null if not found
     */
    public function getDomainNameById(int $zoneId): ?string
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "SELECT name FROM $domains_table WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result ?: null;
    }

    /**
     * Get a complete list of zones accessible by the current user
     *
     * @param int|null $userId Optional user ID to filter zones
     * @param bool $viewOthers Whether to view zones owned by other users
     * @param array $filters Optional filters for zones
     * @param int $offset Pagination offset
     * @param int $limit Maximum number of records to return
     * @return array List of zones
     */
    public function listZones(?int $userId = null, bool $viewOthers = false, array $filters = [], int $offset = 0, int $limit = 100): array
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $cryptokeys_table = $this->tableNameService->getTable(PdnsTable::CRYPTOKEYS);
        $domainmetadata_table = $this->tableNameService->getTable(PdnsTable::DOMAINMETADATA);

        $query = "SELECT
                $domains_table.id,
                $domains_table.name,
                $domains_table.type,
                COUNT($records_table.id) AS count_records,
                users.username,
                users.fullname,
                COUNT($cryptokeys_table.id) > 0 OR COUNT($domainmetadata_table.id) > 0 AS secured,
                zones.comment
            FROM $domains_table
            LEFT JOIN zones ON $domains_table.id = zones.domain_id
            LEFT JOIN $records_table ON $records_table.domain_id = $domains_table.id AND $records_table.type IS NOT NULL
            LEFT JOIN users ON users.id = zones.owner
            LEFT JOIN $cryptokeys_table ON $domains_table.id = $cryptokeys_table.domain_id AND $cryptokeys_table.active
            LEFT JOIN $domainmetadata_table ON $domains_table.id = $domainmetadata_table.domain_id AND $domainmetadata_table.kind = 'PRESIGNED'
            WHERE 1=1";

        $params = [];

        // Filter by owner if requested
        if ($userId !== null && !$viewOthers) {
            $query .= " AND zones.owner = :userId";
            $params[':userId'] = $userId;
        }

        // Apply additional filters
        if (isset($filters['type']) && in_array($filters['type'], ['MASTER', 'SLAVE', 'NATIVE'])) {
            $query .= " AND $domains_table.type = :type";
            $params[':type'] = $filters['type'];
        }

        if (isset($filters['search']) && !empty($filters['search'])) {
            $query .= " AND $domains_table.name LIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        // Group by required fields
        $query .= " GROUP BY $domains_table.name, $domains_table.id, $domains_table.type, users.username, users.fullname, zones.comment";

        // Add ordering
        $query .= " ORDER BY $domains_table.name ASC";

        // Add pagination
        $query .= " LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        $stmt = $this->db->prepare($query);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $zones = [];

        foreach ($results as $row) {
            $name = $row['name'];
            if (!isset($zones[$name])) {
                $zones[$name] = [
                    'id' => $row['id'],
                    'name' => $name,
                    'utf8_name' => DnsIdnService::toUtf8($name),
                    'type' => $row['type'],
                    'count_records' => $row['count_records'],
                    'comment' => $row['comment'] ?? '',
                    'secured' => $row['secured'],
                    'owners' => [],
                    'full_names' => [],
                    'users' => []
                ];
            }

            $zones[$name]['owners'][] = $row['username'];
            $zones[$name]['full_names'][] = $row['fullname'] ?: '';
            $zones[$name]['users'][] = $row['username'];
        }

        // Convert associative array to indexed array for consistent API response
        return array_values($zones);
    }

    /**
     * Check if a zone exists and is accessible by a user
     *
     * @param int $zoneId The zone ID
     * @param int|null $userId Optional user ID to check ownership
     * @return bool True if the zone exists and is accessible by the user
     */
    public function zoneExists(int $zoneId, ?int $userId = null): bool
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "SELECT 1 FROM $domains_table";

        if ($userId !== null) {
            $query .= " LEFT JOIN zones ON $domains_table.id = zones.domain_id";
            $query .= " WHERE $domains_table.id = :id AND zones.owner = :userId";
        } else {
            $query .= " WHERE $domains_table.id = :id";
        }

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);

        if ($userId !== null) {
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Get a zone by ID with full details
     *
     * @param int $zoneId The zone ID
     * @return array|null The zone data or null if not found
     */
    public function getZone(int $zoneId): ?array
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $cryptokeys_table = $this->tableNameService->getTable(PdnsTable::CRYPTOKEYS);
        $domainmetadata_table = $this->tableNameService->getTable(PdnsTable::DOMAINMETADATA);

        // First get the zone details
        $query = "SELECT
                $domains_table.id,
                $domains_table.name,
                $domains_table.type,
                COUNT($records_table.id) AS count_records,
                users.username,
                users.fullname,
                COUNT($cryptokeys_table.id) > 0 OR COUNT($domainmetadata_table.id) > 0 AS secured,
                zones.comment
            FROM $domains_table
            LEFT JOIN zones ON $domains_table.id = zones.domain_id
            LEFT JOIN $records_table ON $records_table.domain_id = $domains_table.id AND $records_table.type IS NOT NULL
            LEFT JOIN users ON users.id = zones.owner
            LEFT JOIN $cryptokeys_table ON $domains_table.id = $cryptokeys_table.domain_id AND $cryptokeys_table.active
            LEFT JOIN $domainmetadata_table ON $domains_table.id = $domainmetadata_table.domain_id AND $domainmetadata_table.kind = 'PRESIGNED'
            WHERE $domains_table.id = :id
            GROUP BY $domains_table.name, $domains_table.id, $domains_table.type, users.username, users.fullname, zones.comment";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        $zone = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$zone) {
            return null;
        }

        // Add additional properties
        $zone['utf8_name'] = DnsIdnService::toUtf8($zone['name']);
        $zone['owners'] = [$zone['username']];
        $zone['full_names'] = [$zone['fullname'] ?: ''];
        $zone['users'] = [$zone['username']];

        return $zone;
    }

    /**
     * Get a zone by name with full details
     *
     * @param string $zoneName The zone name
     * @return array|null The zone data or null if not found
     */
    public function getZoneByName(string $zoneName): ?array
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        // First find the zone ID
        $query = "SELECT id FROM $domains_table WHERE name = :name";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':name', $zoneName, PDO::PARAM_STR);
        $stmt->execute();

        $zoneId = $stmt->fetchColumn();

        if (!$zoneId) {
            return null;
        }

        // Then get the full zone details
        return $this->getZone($zoneId);
    }

    /**
     * Find forward zones associated with reverse zones through PTR records
     *
     * @param array $reverseZoneIds Array of reverse zone IDs
     * @return array Array of PTR record matches with forward zone information
     */
    public function findForwardZonesByPtrRecords(array $reverseZoneIds): array
    {
        if (empty($reverseZoneIds)) {
            return [];
        }

        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        // Build placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($reverseZoneIds), '?'));

        // Get database-compatible concatenation function
        $db_type = $this->config->get('database', 'type');
        $concat_expr = DbCompat::concat($db_type, ["'%'", 'd.name']);

        // Single optimized query that joins PTR records with forward zones
        $query = "SELECT 
                    r.domain_id AS reverse_domain_id, 
                    d.id AS forward_domain_id, 
                    d.name AS forward_domain_name,
                    r.content AS ptr_content
                  FROM $records_table r
                  JOIN $domains_table d ON r.content LIKE $concat_expr
                  WHERE r.domain_id IN ($placeholders)
                    AND r.type = 'PTR'
                    AND d.name NOT LIKE '%.arpa'
                  ORDER BY LENGTH(d.name) DESC";

        $stmt = $this->db->prepare($query);

        // Bind all zone IDs
        $paramIndex = 1;
        foreach ($reverseZoneIds as $zoneId) {
            $stmt->bindValue($paramIndex, $zoneId, PDO::PARAM_INT);
            $paramIndex++;
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if zone exists by ID
     *
     * @param int $zoneId The zone ID
     * @return bool True if zone exists
     */
    public function zoneIdExists(int $zoneId): bool
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "SELECT 1 FROM $domains_table WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Get domain type by zone ID
     *
     * @param int $zoneId The zone ID
     * @return string The domain type (MASTER, SLAVE, NATIVE)
     */
    public function getDomainType(int $zoneId): string
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "SELECT type FROM $domains_table WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetchColumn();
        return $result ?: '';
    }

    /**
     * Get slave master by zone ID
     *
     * @param int $zoneId The zone ID
     * @return string|null The slave master or null if not found
     */
    public function getDomainSlaveMaster(int $zoneId): ?string
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "SELECT master FROM $domains_table WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Get zone comment by zone ID
     *
     * @param int $zoneId The zone ID
     * @return string|null The zone comment or null if not found
     */
    public function getZoneComment(int $zoneId): ?string
    {
        $query = "SELECT comment FROM zones WHERE domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Update zone comment
     *
     * @param int $zoneId The zone ID
     * @param string $comment The new comment
     * @return bool True if updated successfully
     */
    public function updateZoneComment(int $zoneId, string $comment): bool
    {
        $query = "UPDATE zones SET comment = :comment WHERE domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':comment', $comment, PDO::PARAM_STR);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Get users who own a zone
     *
     * @param int $zoneId The zone ID
     * @return array Array of user information
     */
    public function getZoneOwners(int $zoneId): array
    {
        $query = "SELECT u.id, u.username, u.fullname 
                  FROM zones z
                  JOIN users u ON z.owner = u.id
                  WHERE z.domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add owner to zone
     *
     * @param int $zoneId The zone ID
     * @param int $userId The user ID
     * @return bool True if added successfully
     */
    public function addOwnerToZone(int $zoneId, int $userId): bool
    {
        // Get the zone_templ_id from an existing zone record for this domain
        $getTemplateQuery = "SELECT zone_templ_id FROM zones WHERE domain_id = :domain_id LIMIT 1";
        $getStmt = $this->db->prepare($getTemplateQuery);
        $getStmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $getStmt->execute();
        $templateResult = $getStmt->fetch(PDO::FETCH_ASSOC);

        $zoneTemplId = $templateResult ? $templateResult['zone_templ_id'] : 0;

        $query = "INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (:domain_id, :owner, :zone_templ_id)";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_templ_id', $zoneTemplId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Remove owner from zone
     *
     * @param int $zoneId The zone ID
     * @param int $userId The user ID
     * @return bool True if removed successfully
     */
    public function removeOwnerFromZone(int $zoneId, int $userId): bool
    {
        $query = "DELETE FROM zones WHERE domain_id = :domain_id AND owner = :owner";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Check if user is already an owner of the zone
     *
     * @param int $zoneId The zone ID
     * @param int $userId The user ID
     * @return bool True if user is already an owner
     */
    public function isUserZoneOwner(int $zoneId, int $userId): bool
    {
        $query = "SELECT COUNT(id) FROM zones WHERE owner = :user_id AND domain_id = :domain_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Get zone ID by name
     *
     * @param string $zoneName The zone name
     * @return int|null The zone ID or null if not found
     */
    public function getZoneIdByName(string $zoneName): ?int
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "SELECT id FROM $domains_table WHERE name = :name";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':name', $zoneName, PDO::PARAM_STR);
        $stmt->execute();

        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    }

    /**
     * Create a new domain
     *
     * @param string $domain Domain name
     * @param int $owner Owner user ID
     * @param string $type Domain type (MASTER, SLAVE, NATIVE)
     * @param string $slaveMaster Master IP for slave zones
     * @param string $zoneTemplate Zone template to use
     * @return bool True if domain was created successfully
     */
    public function createDomain(string $domain, int $owner, string $type, string $slaveMaster = '', string $zoneTemplate = 'none'): bool
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        // Insert into domains table
        $query = "INSERT INTO $domains_table (name, type, master) VALUES (:name, :type, :master)";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':name', $domain, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':master', $slaveMaster, PDO::PARAM_STR);

        if (!$stmt->execute()) {
            return false;
        }

        $domainId = $this->db->lastInsertId();

        // Insert into zones table for ownership
        $query = "INSERT INTO zones (domain_id, owner, comment) VALUES (:domain_id, :owner, :comment)";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':domain_id', $domainId, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
        $stmt->bindValue(':comment', '', PDO::PARAM_STR);

        return $stmt->execute();
    }

    /**
     * Delete a zone by ID
     *
     * @param int $zoneId The zone ID
     * @return bool True if zone was deleted successfully
     */
    public function deleteZone(int $zoneId): bool
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        // Delete records first
        $query = "DELETE FROM $records_table WHERE domain_id = :domain_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        // Delete from zones table
        $query = "DELETE FROM zones WHERE domain_id = :domain_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        // Delete from domains table
        $query = "DELETE FROM $domains_table WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Update zone metadata
     *
     * @param int $zoneId The zone ID
     * @param array $updates Array of field => value pairs to update
     * @return bool True if zone was updated successfully
     */
    public function updateZone(int $zoneId, array $updates): bool
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $allowedFields = ['name', 'type', 'master'];
        $setClause = [];
        $params = [':id' => $zoneId];

        foreach ($updates as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $setClause[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }

        if (empty($setClause)) {
            return false;
        }

        $query = "UPDATE $domains_table SET " . implode(', ', $setClause) . " WHERE id = :id";
        $stmt = $this->db->prepare($query);

        return $stmt->execute($params);
    }

    public function getAllZones(?int $offset = null, ?int $limit = null): array
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $query = "SELECT d.id, d.name, d.type, d.master,
                         COALESCE(z.owner, 0) as owner,
                         COUNT(DISTINCT r.id) as record_count
                  FROM $domains_table d
                  LEFT JOIN zones z ON d.id = z.domain_id
                  LEFT JOIN $records_table r ON d.id = r.domain_id
                  GROUP BY d.id, d.name, d.type, d.master, z.owner
                  ORDER BY d.name";

        // Add pagination only if limit is specified
        if ($limit !== null && $limit > 0) {
            $query .= " LIMIT :limit OFFSET :offset";
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset ?? 0, PDO::PARAM_INT);
        } else {
            $stmt = $this->db->prepare($query);
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getZoneCount(): int
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "SELECT COUNT(*) FROM $domains_table";
        $stmt = $this->db->query($query);

        return (int)$stmt->fetchColumn();
    }

    public function getZoneById(int $zoneId): ?array
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "SELECT d.id, d.name, d.type, d.master,
                         COALESCE(z.owner, 0) as owner,
                         COUNT(DISTINCT r.id) as record_count
                  FROM $domains_table d
                  LEFT JOIN zones z ON d.id = z.domain_id
                  LEFT JOIN " . $this->tableNameService->getTable(PdnsTable::RECORDS) . " r ON d.id = r.domain_id
                  WHERE d.id = :id
                  GROUP BY d.id, d.name, d.type, d.master, z.owner";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
