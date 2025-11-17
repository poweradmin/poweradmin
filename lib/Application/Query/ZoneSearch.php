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

namespace Poweradmin\Application\Query;

use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Infrastructure\Utility\SortHelper;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;

class ZoneSearch extends BaseSearch
{
    /**
     * Search for zones based on specified parameters.
     *
     * @param array $parameters An array of search parameters.
     * @param string $permission_view The permission view for the search (e.g. 'all' or 'own' zones).
     * @param string $sort_zones_by The column to sort the zone results by.
     * @param string $zone_sort_direction
     * @param int $iface_rowamount The number of rows to display per page.
     * @param bool $iface_zone_comments The number of zone comments to display.
     * @param int $page The current page number.
     * @return array An array of found zones.
     */
    public function searchZones(array $parameters, string $permission_view, string $sort_zones_by, string $zone_sort_direction, int $iface_rowamount, bool $iface_zone_comments, int $page): array
    {
        $foundZones = array();

        list($reverse_search_string, $parameters, $search_string) = $this->buildSearchString($parameters);

        if ($parameters['zones']) {
            $foundZones = $this->fetchZones($parameters, $search_string, $parameters['reverse'], $reverse_search_string, $permission_view, $sort_zones_by, $zone_sort_direction, $iface_rowamount, $iface_zone_comments, $page);
        }

        return $foundZones;
    }

    /**
     * Prepares the list of found zones by aggregating owner details and converting domain names to UTF-8.
     *
     * @param array $zones An array of zone data retrieved from the database.
     * @return array An array of prepared zone data with aggregated owner details and domain names converted to UTF-8.
     */
    public function prepareFoundZones(array $zones): array
    {
        $foundZones = [];

        if ($zones) {
            foreach ($zones as $zone_id => $zone_array) {
                $zone_owner_fullnames = [];
                $zone_owner_ids = [];
                foreach ($zone_array as $zone_entry) {
                    $zone_owner_ids[] = $zone_entry['owner'];
                    $zone_owner_fullnames[] = $zone_entry['fullname'] != "" ? $zone_entry['fullname'] : $zone_entry['username'];
                }
                $zones[$zone_id][0]['owner'] = implode(', ', $zone_owner_ids);
                $zones[$zone_id][0]['fullname'] = implode(', ', $zone_owner_fullnames);
                $found_zone = $zones[$zone_id][0];
                $found_zone['name'] = DnsIdnService::toUtf8($found_zone['name'] ?? '');
                $foundZones[] = $found_zone;
            }
        }
        return $foundZones;
    }

    /**
     * Fetch zones based on specified search criteria and pagination.
     *
     * @param array $parameters Search parameters
     * @param mixed $search_string Search string for matching zones
     * @param bool $reverse Whether to perform a reverse search
     * @param mixed $reverse_search_string Reverse search string for matching zones
     * @param string $permission_view Permission view for the search
     * @param string $sort_zones_by Column to sort zones by
     * @param string $zone_sort_direction Sort direction
     * @param int $iface_rowamount Rows per page
     * @param bool $iface_zone_comments Whether to display zone comments
     * @param int $page Current page number
     * @return array Found zones
     */
    public function fetchZones(
        array $parameters,
        mixed $search_string,
        bool $reverse,
        mixed $reverse_search_string,
        string $permission_view,
        string $sort_zones_by,
        string $zone_sort_direction,
        int $iface_rowamount,
        bool $iface_zone_comments,
        int $page
    ): array {
        $offset = ($page - 1) * $iface_rowamount;

        $tableNameService = new TableNameService($this->config);
        $domains_table = $tableNameService->getTable(PdnsTable::DOMAINS);
        $records_table = $tableNameService->getTable(PdnsTable::RECORDS);

        $db_type = $this->config->get('database', 'type');
        $sort_zones_by = $sort_zones_by === 'name' ? SortHelper::getZoneSortOrder($domains_table, $db_type, $zone_sort_direction) : "$sort_zones_by $zone_sort_direction";

        $comment_field = $iface_zone_comments ? ', z.comment' : '';

        // Prepare query parameters
        $params = [];

        // Build WHERE conditions
        $whereConditions = $this->buildWhereConditionsFetch($domains_table, $search_string, $reverse, $reverse_search_string, $iface_zone_comments, $parameters, $permission_view, $params);

        $zonesQuery = "
            SELECT
                $domains_table.id,
                $domains_table.name,
                $domains_table.type,
                z.id as zone_id,
                z.domain_id,
                z.owner,
                u.id as user_id,
                u.fullname,
                u.username,
                record_count.count_records
                $comment_field
            FROM
                $domains_table
            LEFT JOIN zones z on $domains_table.id = z.domain_id
            LEFT JOIN users u on z.owner = u.id
            LEFT JOIN (SELECT COUNT(domain_id) AS count_records, domain_id FROM $records_table WHERE type IS NOT NULL GROUP BY domain_id) record_count ON record_count.domain_id=$domains_table.id
            WHERE
                " . $whereConditions .
            ' ORDER BY ' . $sort_zones_by .
            ' LIMIT ' . $iface_rowamount . ' OFFSET ' . $offset;

        $stmt = $this->db->prepare($zonesQuery);
        $stmt->execute($params);
        $zonesResponse = $stmt;

        $zones = [];
        while ($zone = $zonesResponse->fetch()) {
            $zones[$zone['id']][] = $zone;
        }

        return $this->prepareFoundZones($zones);
    }

    /**
     * Get the total number of zones based on the specified search criteria.
     *
     * @param array $parameters Array of parameters to configure the search.
     * @param string $permission_view The permission view for the search (e.g. 'all' or 'own' zones).
     * @return int The total number of zones found.
     */
    public function getTotalZones(array $parameters, string $permission_view): int
    {
        list($reverse_search_string, $parameters, $search_string) = $this->buildSearchString($parameters);

        return $this->getFoundZones($parameters, $search_string, $parameters['reverse'], $reverse_search_string, $permission_view);
    }

    /**
     * Get the number of found zones based on the search criteria.
     *
     * @param array $parameters An array of search parameters.
     * @param mixed $search_string The search string to be used in the query.
     * @param bool $reverse Whether to perform a reverse search or not.
     * @param mixed $reverse_search_string The reversed search string to be used in the query.
     * @param string $permission_view The permission view for the search (e.g. 'all' or 'own' zones).
     * @return int The number of zones found.
     */
    public function getFoundZones(array $parameters, mixed $search_string, bool $reverse, mixed $reverse_search_string, string $permission_view): int
    {
        $tableNameService = new TableNameService($this->config);
        $domains_table = $tableNameService->getTable(PdnsTable::DOMAINS);
        $records_table = $tableNameService->getTable(PdnsTable::RECORDS);

        // Prepare query parameters
        $params = [];

        // Build WHERE conditions
        $whereConditions = $this->buildWhereConditionsCount($domains_table, $search_string, $reverse, $reverse_search_string, $parameters, $permission_view, $params);

        // Build a query that correctly applies permission filters for accurate counting
        $zonesQuery = "
        SELECT
            COUNT(DISTINCT $domains_table.id)
        FROM
            $domains_table
        LEFT JOIN zones z on $domains_table.id = z.domain_id
        LEFT JOIN users u on z.owner = u.id
        LEFT JOIN (SELECT COUNT(domain_id) AS count_records, domain_id FROM $records_table WHERE type IS NOT NULL GROUP BY domain_id) record_count ON record_count.domain_id=$domains_table.id
        WHERE
            " . $whereConditions;

        $stmt = $this->db->prepare($zonesQuery);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Build WHERE conditions for fetch zones query
     */
    private function buildWhereConditionsFetch(string $domains_table, mixed $search_string, bool $reverse, mixed $reverse_search_string, bool $iface_zone_comments, array $parameters, string $permission_view, array &$params): string
    {
        // Add main search parameters
        $params[':search_string1'] = $search_string;

        // Build WHERE conditions
        $whereConditions = "(($domains_table.name LIKE :search_string1";

        if ($reverse) {
            $whereConditions .= " OR $domains_table.name LIKE :reverse_search_string";
            $params[':reverse_search_string'] = $reverse_search_string;
        }

        $whereConditions .= ')';

        if ($iface_zone_comments && $parameters['comments']) {
            $whereConditions .= " OR z.comment LIKE :search_string_comment";
            $params[':search_string_comment'] = $search_string;
        }

        $whereConditions .= ')';

        if ($permission_view == 'own') {
            // Check both direct ownership and group ownership
            $whereConditions .= ' AND (z.owner = :user_id OR EXISTS (
                SELECT 1 FROM zones_groups zg
                INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                WHERE zg.domain_id = ' . $domains_table . '.id AND ugm.user_id = :user_id
            ))';
            $params[':user_id'] = $_SESSION['userid'];
        }

        return $whereConditions;
    }

    /**
     * Build WHERE conditions for count zones query
     */
    private function buildWhereConditionsCount(string $domains_table, mixed $search_string, bool $reverse, mixed $reverse_search_string, array $parameters, string $permission_view, array &$params): string
    {
        // Add main search parameters
        $params[':search_string1'] = $search_string;

        // Build WHERE conditions
        $whereConditions = "(($domains_table.name LIKE :search_string1";

        if ($reverse) {
            $whereConditions .= " OR $domains_table.name LIKE :reverse_search_string";
            $params[':reverse_search_string'] = $reverse_search_string;
        }

        $whereConditions .= ')';

        if ($parameters['comments']) {
            $whereConditions .= " OR z.comment LIKE :search_string_comment";
            $params[':search_string_comment'] = $search_string;
        }

        $whereConditions .= ')';

        if ($permission_view == 'own') {
            // Check both direct ownership and group ownership
            $whereConditions .= ' AND (z.owner = :user_id OR EXISTS (
                SELECT 1 FROM zones_groups zg
                INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                WHERE zg.domain_id = ' . $domains_table . '.id AND ugm.user_id = :user_id
            ))';
            $params[':user_id'] = $_SESSION['userid'];
        }

        return $whereConditions;
    }
}
