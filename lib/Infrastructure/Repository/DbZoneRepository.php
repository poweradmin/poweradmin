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
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Infrastructure\Database\DbCompat;
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

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->db_type = $config->get('database', 'type');
        $this->pdns_db_name = $config->get('database', 'pdns_name');
        $this->naturalSorting = new NaturalSorting();
        $this->reverseDomainNaturalSorting = new ReverseDomainNaturalSorting();
        $this->reverseZoneSorting = new ReverseZoneSorting();
    }

    public function getDistinctStartingLetters(int $userId, bool $viewOthers): array
    {
        $domains_table = $this->pdns_db_name ? $this->pdns_db_name . '.domains' : 'domains';

        $query = "SELECT DISTINCT " . DbCompat::substr($this->db_type) . "($domains_table.name, 1, 1) AS letter FROM $domains_table";

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
        $domains_table = $this->pdns_db_name ? $this->pdns_db_name . '.domains' : 'domains';
        $records_table = $this->pdns_db_name ? $this->pdns_db_name . '.records' : 'records';
        $cryptokeys_table = $this->pdns_db_name ? $this->pdns_db_name . '.cryptokeys' : 'cryptokeys';
        $domainmetadata_table = $this->pdns_db_name ? $this->pdns_db_name . '.domainmetadata' : 'domainmetadata';

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
        $domains_table = $this->pdns_db_name ? $this->pdns_db_name . '.domains' : 'domains';

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
        $domains_table = $this->pdns_db_name ? $this->pdns_db_name . '.domains' : 'domains';
        $records_table = $this->pdns_db_name ? $this->pdns_db_name . '.records' : 'records';
        $cryptokeys_table = $this->pdns_db_name ? $this->pdns_db_name . '.cryptokeys' : 'cryptokeys';
        $domainmetadata_table = $this->pdns_db_name ? $this->pdns_db_name . '.domainmetadata' : 'domainmetadata';

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
        $domains_table = $this->pdns_db_name ? $this->pdns_db_name . '.domains' : 'domains';

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
        $domains_table = $this->pdns_db_name ? $this->pdns_db_name . '.domains' : 'domains';
        $records_table = $this->pdns_db_name ? $this->pdns_db_name . '.records' : 'records';
        $cryptokeys_table = $this->pdns_db_name ? $this->pdns_db_name . '.cryptokeys' : 'cryptokeys';
        $domainmetadata_table = $this->pdns_db_name ? $this->pdns_db_name . '.domainmetadata' : 'domainmetadata';

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
        $domains_table = $this->pdns_db_name ? $this->pdns_db_name . '.domains' : 'domains';

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
}
