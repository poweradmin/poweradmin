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

class RecordSearch
{
    private $db;
    private string $db_type;

    public function __construct($db, string $db_type)
    {
        $this->db = $db;
        $this->db_type = $db_type;
    }

    /**
     * Search for Records
     *
     * @param array $parameters Array with parameters which configures function
     * @param string $permission_view User permitted to view 'all' or 'own' zones
     * @param string $sort_records_by Column to sort record results
     * @param int $iface_rowamount
     * @return array
     */
    public function search_records(array $parameters, string $permission_view, string $sort_records_by, int $iface_rowamount): array
    {
        global $iface_search_group_records;

        $foundRecords = array();

        list($reverse_search_string, $parameters, $search_string) = $this->buildSearchString($parameters);

        $originalSqlMode = $this->handleSqlMode();

        if ($parameters['records']) {
            $foundRecords = $this->fetchRecords($search_string, $parameters['reverse'], $reverse_search_string, $permission_view, $iface_search_group_records, $sort_records_by, $iface_rowamount, $foundRecords);
        }

        $this->restoreSqlMode($originalSqlMode);

        return $foundRecords;
    }

    /**
     * @param array $parameters
     * @return array
     */
    public function buildSearchString(array $parameters): array
    {
        if ($parameters['reverse']) {
            if (filter_var($parameters['query'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $reverse_search_string = implode('.', array_reverse(explode('.', $parameters['query'])));
            } elseif (filter_var($parameters['query'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $reverse_search_string = unpack('H*hex', inet_pton($parameters['query']));
                $reverse_search_string = implode('.', array_reverse(str_split($reverse_search_string['hex'])));
            } else {
                $parameters['reverse'] = false;
                $reverse_search_string = '';
            }

            $reverse_search_string = $this->db->quote('%' . $reverse_search_string . '%', 'text');
        }

        $needle = idn_to_ascii(trim($parameters['query']), IDNA_NONTRANSITIONAL_TO_ASCII);
        $search_string = ($parameters['wildcard'] ? '%' : '') . $needle . ($parameters['wildcard'] ? '%' : '');
        return array($reverse_search_string, $parameters, $search_string);
    }

    /**
     * @return string
     */
    public function handleSqlMode(): string
    {
        $originalSqlMode = '';

        if ($this->db_type === 'mysql') {
            $originalSqlMode = $this->db->queryOne("SELECT @@GLOBAL.sql_mode");

            if (str_contains($originalSqlMode, 'ONLY_FULL_GROUP_BY')) {
                $newSqlMode = str_replace('ONLY_FULL_GROUP_BY,', '', $originalSqlMode);
                $this->db->exec("SET SESSION sql_mode = '$newSqlMode'");
            } else {
                $originalSqlMode = '';
            }
        }
        return $originalSqlMode;
    }

    /**
     * @param string $originalSqlMode
     * @return void
     */
    public function restoreSqlMode(string $originalSqlMode): void
    {
        if ($this->db_type === 'mysql' && $originalSqlMode !== '') {
            $this->db->exec("SET SESSION sql_mode = '$originalSqlMode'");
        }
    }

    /**
     * @param mixed $search_string
     * @param $reverse
     * @param mixed $reverse_search_string
     * @param string $permission_view
     * @param bool $iface_search_group_records
     * @param string $sort_records_by
     * @param int $iface_rowamount
     * @param array $foundRecords
     * @return array
     */
    public function fetchRecords(mixed $search_string, $reverse, mixed $reverse_search_string, string $permission_view, bool $iface_search_group_records, string $sort_records_by, int $iface_rowamount, array $foundRecords): array
    {
        $recordsQuery = '
            SELECT
                records.id,
                records.domain_id,
                records.name,
                records.type,
                records.content,
                records.ttl,
                records.prio,
                z.id as zone_id,
                z.owner,
                u.id as user_id,
                u.fullname
            FROM
                records
            LEFT JOIN zones z on records.domain_id = z.domain_id
            LEFT JOIN users u on z.owner = u.id
            WHERE
                (records.name LIKE ' . $this->db->quote($search_string, 'text') . ' OR records.content LIKE ' . $this->db->quote($search_string, 'text') .
            ($reverse ? ' OR records.name LIKE ' . $reverse_search_string . ' OR records.content LIKE ' . $reverse_search_string : '') . ')' .
            ($permission_view == 'own' ? 'AND z.owner = ' . $this->db->quote($_SESSION['userid'], 'integer') : '') .
            ($iface_search_group_records ? ' GROUP BY records.name, records.content ' : '') . // May not work correctly with MySQL strict mode
            ' ORDER BY ' . $sort_records_by .
            ' LIMIT ' . $iface_rowamount;

        $recordsResponse = $this->db->query($recordsQuery);

        while ($record = $recordsResponse->fetch()) {
            $found_record = $record;
            $found_record['name'] = idn_to_utf8($found_record['name'], IDNA_NONTRANSITIONAL_TO_ASCII);
            $foundRecords[] = $found_record;
        }
        return $foundRecords;
    }
}