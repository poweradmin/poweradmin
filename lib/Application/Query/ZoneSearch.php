<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

class ZoneSearch extends BaseSearch
{
    /**
     * Search for Zones
     *
     * @param array $parameters Array with parameters which configures function
     * @param string $permission_view User permitted to view 'all' or 'own' zones
     * @param string $sort_zones_by Column to sort zone results
     * @param int $iface_rowamount
     * @return array
     */
    public function search_zones(array $parameters, string $permission_view, string $sort_zones_by, int $iface_rowamount, int $page): array
    {
        $foundZones = array();

        list($reverse_search_string, $parameters, $search_string) = $this->buildSearchString($parameters);

        $originalSqlMode = $this->handleSqlMode();

        if ($parameters['zones']) {
            $foundZones = $this->fetchZones($search_string, $parameters['reverse'], $reverse_search_string, $permission_view, $sort_zones_by, $iface_rowamount, $page);
        }

        $this->restoreSqlMode($originalSqlMode);

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
     * @param mixed $search_string
     * @param $reverse
     * @param mixed $reverse_search_string
     * @param string $permission_view
     * @param string $sort_zones_by
     * @param int $iface_rowamount Items per page
     * @param int $page
     * @return array
     */
    public function fetchZones(mixed $search_string, $reverse, mixed $reverse_search_string, string $permission_view, string $sort_zones_by, int $iface_rowamount, int $page): array
    {
        $offset = ($page - 1) * $iface_rowamount;

        $zonesQuery = '
            SELECT
                domains.id,
                domains.name,
                domains.type,
                z.id as zone_id,
                z.domain_id,
                z.owner,
                u.id as user_id,
                u.fullname,
                u.username,
                record_count.count_records
            FROM
                domains
            LEFT JOIN zones z on domains.id = z.domain_id
            LEFT JOIN users u on z.owner = u.id
            LEFT JOIN (SELECT COUNT(domain_id) AS count_records, domain_id FROM records WHERE type IS NOT NULL GROUP BY domain_id) record_count ON record_count.domain_id=domains.id
            WHERE
                (domains.name LIKE ' . $this->db->quote($search_string, 'text') .
            ($reverse ? ' OR domains.name LIKE ' . $reverse_search_string : '') . ') ' .
            ($permission_view == 'own' ? ' AND z.owner = ' . $this->db->quote($_SESSION['userid'], 'integer') : '') .
            ' ORDER BY ' . $sort_zones_by . ', z.owner' .
            ' LIMIT ' . $iface_rowamount . ' OFFSET ' . $offset;

        $zonesResponse = $this->db->query($zonesQuery);

        $zones = [];
        while ($zone = $zonesResponse->fetch()) {
            $zones[$zone['id']][] = $zone;
        }

        return $this->prepareFoundZones($zones);
    }
}