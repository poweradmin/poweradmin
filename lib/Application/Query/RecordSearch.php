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

class RecordSearch extends BaseSearch
{
    /**
     * Search for records based on specified parameters.
     *
     * @param array $parameters An array of search parameters.
     * @param string $permission_view The permission view for the search.
     * @param string $sort_records_by The column to sort the records by.
     * @param string $record_sort_direction
     * @param bool $iface_search_group_records Whether to group records or not.
     * @param int $iface_rowamount The number of rows to display per page.
     * @param bool $iface_record_comments Whether to display record comments or not.
     * @param int $page The current page number (default is 1).
     * @return array An array of found records.
     */
    public function searchRecords(array $parameters, string $permission_view, string $sort_records_by, string $record_sort_direction, bool $iface_search_group_records, int $iface_rowamount, bool $iface_record_comments, int $page = 1): array
    {
        $foundRecords = array();

        list($reverse_search_string, $parameters, $search_string) = $this->buildSearchString($parameters);

        $originalSqlMode = $this->handleSqlMode();

        if ($parameters['records']) {
            $foundRecords = $this->fetchRecords(
                $parameters,
                $search_string,
                $parameters['reverse'],
                $reverse_search_string,
                $permission_view,
                $iface_search_group_records,
                $sort_records_by,
                $record_sort_direction,
                $iface_rowamount,
                $iface_record_comments,
                $page
            );
        }

        $this->restoreSqlMode($originalSqlMode);

        return $foundRecords;
    }

    /**
     * Fetch records based on the given search criteria.
     *
     * @param array $parameters Search parameters
     * @param mixed $search_string Search string for matching records
     * @param bool $reverse Whether to perform a reverse search
     * @param mixed $reverse_search_string Reverse search string for matching records
     * @param string $permission_view Permission view for the search
     * @param bool $iface_search_group_records Whether to search group records
     * @param string $sort_records_by Column to sort records by
     * @param string $record_sort_direction Sort direction
     * @param int $iface_rowamount Rows per page
     * @param bool $iface_record_comments Whether to display record comments
     * @param int $page Current page number
     * @return array Found records
     */
    public function fetchRecords(
        array $parameters,
        mixed $search_string,
        bool $reverse,
        mixed $reverse_search_string,
        string $permission_view,
        bool $iface_search_group_records,
        string $sort_records_by,
        string $record_sort_direction,
        int $iface_rowamount,
        bool $iface_record_comments,
        int $page
    ): array {
        $offset = ($page - 1) * $iface_rowamount;

        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';
        $comments_table = $pdns_db_name ? $pdns_db_name . '.comments' : 'comments';

        $db_type = $this->config->get('database', 'type');
        $sort_records_by = $sort_records_by === 'name' ? SortHelper::getRecordSortOrder($records_table, $db_type, $record_sort_direction) : "$sort_records_by $record_sort_direction";

        // Build query with new type and content filters
        $typeFilter = !empty($parameters['type_filter']) ?
            " AND $records_table.type = " . $this->db->quote($parameters['type_filter'], 'text') : '';

        $contentFilter = '';
        if (!empty($parameters['content_filter'])) {
            // Add wildcards automatically if they're not already present
            $content = $parameters['content_filter'];
            if (strpos($content, '%') === false) {
                $content = '%' . $content . '%';
            }
            $contentFilter = " AND $records_table.content LIKE " . $this->db->quote($content, 'text');
        }

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
            u.fullname" .
            ($iface_record_comments ? ", c.comment" : "") . "
        FROM
            $records_table
        LEFT JOIN zones z on $records_table.domain_id = z.domain_id
        LEFT JOIN users u on z.owner = u.id" .
            ($iface_record_comments ? " LEFT JOIN $comments_table c on $records_table.domain_id = c.domain_id AND $records_table.name = c.name AND $records_table.type = c.type" : "") . "
        WHERE
            (($records_table.name LIKE " . $this->db->quote($search_string, 'text') . " OR $records_table.content LIKE " . $this->db->quote($search_string, 'text') .
            ($reverse ? " OR $records_table.name LIKE " . $this->db->quote($reverse_search_string, 'text') . " OR $records_table.content LIKE " . $this->db->quote($reverse_search_string, 'text') : '') . ')' .
            ($iface_record_comments && $parameters['comments'] ? " OR c.comment LIKE " . $this->db->quote($search_string, 'text') : '') . ')' .
            $typeFilter .
            $contentFilter .
            ($permission_view == 'own' ? ' AND z.owner = ' . $this->db->quote($_SESSION['userid'], 'integer') : '') .
            ($iface_search_group_records ? " GROUP BY $records_table.name, $records_table.content " : '') .
            ' ORDER BY ' . $sort_records_by .
            ' LIMIT ' . $iface_rowamount . ' OFFSET ' . $offset;

        $recordsResponse = $this->db->query($recordsQuery);

        $foundRecords = array();
        while ($record = $recordsResponse->fetch()) {
            $found_record = $record;
            $found_record['name'] = DnsIdnService::toUtf8($found_record['name']);
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
        $foundRecords = $this->getFoundRecords($parameters, $search_string, $parameters['reverse'], $reverse_search_string, $permission_view, $iface_search_group_records);
        $this->restoreSqlMode($originalSqlMode);

        return $foundRecords;
    }

    /**
     * Get the total number of found records based on the given search criteria.
     *
     * @param array $parameters An array of search parameters.
     * @param mixed $search_string The search string to use for matching records.
     * @param bool $reverse Whether to perform a reverse search or not.
     * @param mixed $reverse_search_string The reverse search string to use for matching records.
     * @param string $permission_view The permission view for the search.
     * @param bool $iface_search_group_records Whether to search group records or not.
     * @return int The total number of found records.
     */
    public function getFoundRecords(array $parameters, mixed $search_string, bool $reverse, mixed $reverse_search_string, string $permission_view, bool $iface_search_group_records): int
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';
        $comments_table = $pdns_db_name ? $pdns_db_name . '.comments' : 'comments';
        $groupByClause = $iface_search_group_records ? "GROUP BY $records_table.name, $records_table.content" : '';

        // Add type and content filters
        $typeFilter = !empty($parameters['type_filter']) ?
            " AND $records_table.type = " . $this->db->quote($parameters['type_filter'], 'text') : '';

        $contentFilter = '';
        if (!empty($parameters['content_filter'])) {
            // Add wildcards automatically if they're not already present
            $content = $parameters['content_filter'];
            if (strpos($content, '%') === false) {
                $content = '%' . $content . '%';
            }
            $contentFilter = " AND $records_table.content LIKE " . $this->db->quote($content, 'text');
        }

        // Build a query that correctly applies permission filters for accurate counting
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
            LEFT JOIN $comments_table c on $records_table.domain_id = c.domain_id AND $records_table.name = c.name AND $records_table.type = c.type
            WHERE
                (($records_table.name LIKE " . $this->db->quote($search_string, 'text') . " OR $records_table.content LIKE " . $this->db->quote($search_string, 'text') .
            ($reverse ? " OR $records_table.name LIKE " . $this->db->quote($reverse_search_string, 'text') . " OR $records_table.content LIKE " . $this->db->quote($reverse_search_string, 'text') : '') . ')' .
            ($parameters['comments'] ? " OR c.comment LIKE " . $this->db->quote($search_string, 'text') : '') . ')' .
            $typeFilter .
            $contentFilter .
            ($permission_view == 'own' ? ' AND z.owner = ' . $this->db->quote($_SESSION['userid'], 'integer') : '') .
            " $groupByClause
        ) as grouped_records";

        return (int)$this->db->queryOne($recordsQuery);
    }
}
