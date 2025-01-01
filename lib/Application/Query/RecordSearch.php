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

class RecordSearch extends BaseSearch
{
    /**
     * Search for records based on specified parameters.
     *
     * @param array $parameters An array of search parameters.
     * @param string $permission_view The permission view for the search.
     * @param string $sort_records_by The column to sort the records by.
     * @param bool $iface_search_group_records Whether to group records or not.
     * @param int $iface_rowamount The number of rows to display per page.
     * @param int $page The current page number (default is 1).
     * @return array An array of found records.
     */
    public function searchRecords(array $parameters, string $permission_view, string $sort_records_by, string $record_sort_direction, bool $iface_search_group_records, int $iface_rowamount, int $page = 1): array
    {
        $foundRecords = array();

        list($reverse_search_string, $parameters, $search_string) = $this->buildSearchString($parameters);

        $originalSqlMode = $this->handleSqlMode();

        if ($parameters['records']) {
            $foundRecords = $this->fetchRecords($search_string, $parameters['reverse'], $reverse_search_string, $permission_view, $iface_search_group_records, $sort_records_by, $record_sort_direction, $iface_rowamount, $page);
        }

        $this->restoreSqlMode($originalSqlMode);

        return $foundRecords;
    }

    /**
     * Fetch records based on the given search criteria.
     *
     * @param mixed $search_string The search string to use for matching records.
     * @param bool $reverse Whether to perform a reverse search or not.
     * @param mixed $reverse_search_string The reverse search string to use for matching records.
     * @param string $permission_view The permission view for the search.
     * @param bool $iface_search_group_records Whether to search group records or not.
     * @param string $sort_records_by The column to sort the records by.
     * @param int $iface_rowamount The number of rows to display per page.
     * @param int $page The current page number.
     * @return array An array of found records.
     */
    public function fetchRecords(mixed $search_string, bool $reverse, mixed $reverse_search_string, string $permission_view, bool $iface_search_group_records, string $sort_records_by, string $record_sort_direction, int $iface_rowamount, int $page): array
    {
        $offset = ($page - 1) * $iface_rowamount;

        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $db_type = $this->config->get('db_type');
        $sort_records_by = $sort_records_by === 'name' ? SortHelper::getRecordSortOrder($records_table, $db_type, $record_sort_direction) : "$sort_records_by $record_sort_direction";

        $recordsQuery = "
            SELECT
                $records_table.id,
                $records_table.domain_id,
                $records_table.name,
                $records_table.type,
                $records_table.content,
                $records_table.ttl,
                $records_table.prio,
                $records_table.disabled,
                z.id as zone_id,
                z.owner,
                u.id as user_id,
                u.fullname
            FROM
                $records_table
            LEFT JOIN zones z on $records_table.domain_id = z.domain_id
            LEFT JOIN users u on z.owner = u.id
            WHERE
                ($records_table.name LIKE " . $this->db->quote($search_string, 'text') . " OR $records_table.content LIKE " . $this->db->quote($search_string, 'text') .
            ($reverse ? " OR $records_table.name LIKE " . $this->db->quote($reverse_search_string, 'text') . " OR $records_table.content LIKE " . $this->db->quote($reverse_search_string, 'text') : '') . ')' .
            ($permission_view == 'own' ? 'AND z.owner = ' . $this->db->quote($_SESSION['userid'], 'integer') : '') .
            ($iface_search_group_records ? " GROUP BY $records_table.name, $records_table.content " : '') .
            ' ORDER BY ' . $sort_records_by .
            ' LIMIT ' . $iface_rowamount . ' OFFSET ' . $offset;

        $recordsResponse = $this->db->query($recordsQuery);

        $foundRecords = array();
        while ($record = $recordsResponse->fetch()) {
            $found_record = $record;
            $found_record['name'] = idn_to_utf8($found_record['name'], IDNA_NONTRANSITIONAL_TO_ASCII);
            $found_record['disabled'] = $found_record['disabled'] == '1' ? _('Yes') : _('No');
            $foundRecords[] = $found_record;
        }

        return $foundRecords;
    }

    /**
     * Get the total number of records based on the specified parameters.
     *
     * @param array $parameters An array of search parameters.
     * @param string $permission_view The permission view for the search.
     * @param bool $iface_search_group_records Whether to search group records or not.
     * @return int The total number of found records.
     */
    public function getTotalRecords(array $parameters, string $permission_view, bool $iface_search_group_records): int
    {
        list($reverse_search_string, $parameters, $search_string) = $this->buildSearchString($parameters);

        $originalSqlMode = $this->handleSqlMode();
        $foundRecords = $this->getFoundRecords($search_string, $parameters['reverse'], $reverse_search_string, $permission_view, $iface_search_group_records);
        $this->restoreSqlMode($originalSqlMode);

        return $foundRecords;
    }

    /**
     * Get the total number of found records based on the given search criteria.
     *
     * @param mixed $search_string The search string to use for matching records.
     * @param bool $reverse Whether to perform a reverse search or not.
     * @param mixed $reverse_search_string The reverse search string to use for matching records.
     * @param string $permission_view The permission view for the search.
     * @param bool $iface_search_group_records Whether to search group records or not.
     * @return int The total number of found records.
     */
    public function getFoundRecords(mixed $search_string, bool $reverse, mixed $reverse_search_string, string $permission_view, bool $iface_search_group_records): int
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';
        $groupByClause = $iface_search_group_records ? "GROUP BY $records_table.name, $records_table.content" : '';

        $recordsQuery = "
            SELECT
                COUNT(*)
            FROM (
                SELECT
                    $records_table.id
                FROM
                    $records_table
                LEFT JOIN zones z on $records_table.domain_id = z.domain_id
                LEFT JOIN users u on z.owner = u.id
                WHERE
                    ($records_table.name LIKE " . $this->db->quote($search_string, 'text') . " OR $records_table.content LIKE " . $this->db->quote($search_string, 'text') .
                ($reverse ? " OR $records_table.name LIKE " . $this->db->quote($reverse_search_string, 'text') . " OR $records_table.content LIKE " . $this->db->quote($reverse_search_string, 'text') : '') . ')' .
                ($permission_view == 'own' ? 'AND z.owner = ' . $this->db->quote($_SESSION['userid'], 'integer') : '') .
                " $groupByClause
            ) as grouped_records";

        return (int)$this->db->queryOne($recordsQuery);
    }
}