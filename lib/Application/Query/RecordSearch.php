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

    /**
     * Search for Records
     *
     * @param array $parameters Array with parameters which configures function
     * @param string $permission_view User permitted to view 'all' or 'own' zones
     * @param string $sort_records_by Column to sort record results
     * @param int $iface_rowamount
     * @return array
     */
    public static function search_records(array $parameters, string $permission_view, string $sort_records_by, int $iface_rowamount): array
    {
        global $db, $db_type;
        global $iface_search_group_records;

        $foundRecords = array();

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

            $reverse_search_string = $db->quote('%' . $reverse_search_string . '%', 'text');
        }

        $needle = idn_to_ascii(trim($parameters['query']), IDNA_NONTRANSITIONAL_TO_ASCII);
        $search_string = ($parameters['wildcard'] ? '%' : '') . $needle . ($parameters['wildcard'] ? '%' : '');

        $originalSqlMode = '';

        if ($db_type === 'mysql') {
            $originalSqlMode = $db->queryOne("SELECT @@GLOBAL.sql_mode");

            if (str_contains($originalSqlMode, 'ONLY_FULL_GROUP_BY')) {
                $newSqlMode = str_replace('ONLY_FULL_GROUP_BY,', '', $originalSqlMode);
                $db->exec("SET SESSION sql_mode = '$newSqlMode'");
            } else {
                $originalSqlMode = '';
            }
        }

        if ($parameters['records']) {
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
                (records.name LIKE ' . $db->quote($search_string, 'text') . ' OR records.content LIKE ' . $db->quote($search_string, 'text') .
                ($parameters['reverse'] ? ' OR records.name LIKE ' . $reverse_search_string . ' OR records.content LIKE ' . $reverse_search_string : '') . ')' .
                ($permission_view == 'own' ? 'AND z.owner = ' . $db->quote($_SESSION['userid'], 'integer') : '') .
                ($iface_search_group_records ? ' GROUP BY records.name, records.content ' : '') . // May not work correctly with MySQL strict mode
                ' ORDER BY ' . $sort_records_by .
                ' LIMIT ' . $iface_rowamount;

            $recordsResponse = $db->query($recordsQuery);

            while ($record = $recordsResponse->fetch()) {
                $found_record = $record;
                $found_record['name'] = idn_to_utf8($found_record['name'], IDNA_NONTRANSITIONAL_TO_ASCII);
                $foundRecords[] = $found_record;
            }
        }

        if ($db_type === 'mysql' && $originalSqlMode !== '') {
            $db->exec("SET SESSION sql_mode = '$originalSqlMode'");
        }

        return $foundRecords;
    }
}