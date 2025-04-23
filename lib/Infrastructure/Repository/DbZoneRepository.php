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
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Utility\SortHelper;

class DbZoneRepository implements ZoneRepositoryInterface
{
    private object $db;
    private string $db_type;
    private ?string $pdns_db_name;

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->db_type = $config->get('database', 'type');
        $this->pdns_db_name = $config->get('database', 'pdns_name');
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

        $query .= " ORDER BY " . ($sortBy == "$domains_table.name" ?
            SortHelper::getZoneSortOrder($domains_table, $this->db_type, $sortDirection) :
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
                    'utf8_name' => idn_to_utf8(htmlspecialchars($name), IDNA_NONTRANSITIONAL_TO_ASCII),
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
}
