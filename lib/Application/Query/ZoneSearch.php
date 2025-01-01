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

use Poweradmin\Infrastructure\Utility\SortHelper;

class ZoneSearch extends BaseSearch
{
    /**
     * Search for zones based on specified parameters.
     *
     * @param array $parameters An array of search parameters.
     * @param string $permission_view The permission view for the search (e.g. 'all' or 'own' zones).
     * @param string $sort_zones_by The column to sort the zone results by.
     * @param int $iface_rowamount The number of rows to display per page.
     * @param int $page The current page number.
     * @return array An array of found zones.
     */
    public function searchZones(array $parameters, string $permission_view, string $sort_zones_by, string $zone_sort_direction, int $iface_rowamount, int $page): array
    {
        $foundZones = array();

        list($reverse_search_string, $parameters, $search_string) = $this->buildSearchString($parameters);

        if ($parameters['zones']) {
            $foundZones = $this->fetchZones($search_string, $parameters['reverse'], $reverse_search_string, $permission_view, $sort_zones_by, $zone_sort_direction, $iface_rowamount, $page);
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
                $found_zone['name'] = idn_to_utf8($found_zone['name'], IDNA_NONTRANSITIONAL_TO_ASCII);
                $foundZones[] = $found_zone;
            }
        }
        return $foundZones;
    }

    /**
     * Fetch zones based on specified search criteria and pagination.
     *
     * @param mixed $search_string The search string to use for matching zones.
     * @param bool $reverse Whether to perform a reverse search or not.
     * @param string $reverse_search_string The reverse search string to use for matching zones.
     * @param string $permission_view The permission view for the search (e.g. 'all' or 'own' zones).
     * @param string $sort_zones_by The column to sort the zone results by.
     * @param int $iface_rowamount The number of rows to display per page.
     * @param int $page The current page number.
     * @return array An array of found zones.
     */
    public function fetchZones(mixed $search_string, bool $reverse, mixed $reverse_search_string, string $permission_view, string $sort_zones_by, string $zone_sort_direction, int $iface_rowamount, int $page): array
    {
        $offset = ($page - 1) * $iface_rowamount;

        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $db_type = $this->config->get('db_type');
        $sort_zones_by = $sort_zones_by === 'name' ? SortHelper::getZoneSortOrder($domains_table, $db_type, $zone_sort_direction) : "$sort_zones_by $zone_sort_direction";

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
            FROM
                $domains_table
            LEFT JOIN zones z on $domains_table.id = z.domain_id
            LEFT JOIN users u on z.owner = u.id
            LEFT JOIN (SELECT COUNT(domain_id) AS count_records, domain_id FROM $records_table WHERE type IS NOT NULL GROUP BY domain_id) record_count ON record_count.domain_id=$domains_table.id
            WHERE
                ($domains_table.name LIKE " . $this->db->quote($search_string, 'text') .
            ($reverse ? " OR $domains_table.name LIKE " . $this->db->quote($reverse_search_string, 'text') : '') . ') ' .
            ($permission_view == 'own' ? ' AND z.owner = ' . $this->db->quote($_SESSION['userid'], 'integer') : '') .
            ' ORDER BY ' . $sort_zones_by .
            ' LIMIT ' . $iface_rowamount . ' OFFSET ' . $offset;

        $zonesResponse = $this->db->query($zonesQuery);

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

        return $this->getFoundZones($search_string, $parameters['reverse'], $reverse_search_string, $permission_view);
    }

    /**
     * Get the number of found zones based on the search criteria.
     *
     * @param mixed $search_string The search string to be used in the query.
     * @param bool $reverse Indicates whether to search for reversed search string.
     * @param mixed $reverse_search_string The reversed search string to be used in the query.
     * @param string $permission_view The permission view for the search (e.g. 'all' or 'own' zones).
     * @return int The number of zones found.
     */
    public function getFoundZones(mixed $search_string, bool $reverse, mixed $reverse_search_string, string $permission_view): int
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $zonesQuery = "
            SELECT
                COUNT(*)
            FROM
                $domains_table
            LEFT JOIN zones z on $domains_table.id = z.domain_id
            LEFT JOIN users u on z.owner = u.id
            LEFT JOIN (SELECT COUNT(domain_id) AS count_records, domain_id FROM $records_table WHERE type IS NOT NULL GROUP BY domain_id) record_count ON record_count.domain_id=$domains_table.id
            WHERE
                ($domains_table.name LIKE " . $this->db->quote($search_string, 'text') .
            ($reverse ? " OR $domains_table.name LIKE " . $this->db->quote($reverse_search_string, 'text') : '') . ') ' .
            ($permission_view == 'own' ? ' AND z.owner = ' . $this->db->quote($_SESSION['userid'], 'integer') : '');

        return (int)$this->db->queryOne($zonesQuery);
    }
}