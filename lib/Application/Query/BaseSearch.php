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

use Poweradmin\AppConfiguration;

abstract class BaseSearch
{
    protected object $db;
    protected string $db_type;
    protected AppConfiguration $config;

    public function __construct($db, $config, string $db_type)
    {
        $this->db = $db;
        $this->config = $config;
        $this->db_type = $db_type;
    }

    /**
     * Builds the search string for the given parameters.
     *
     * @param array $parameters An array containing search parameters, including
     *                          'reverse' for reverse DNS lookup, 'query' for the search term,
     *                          and 'wildcard' for enabling/disabling wildcard search.
     * @return array An array containing the reverse search string, the updated parameters, and
     *               the generated search string.
     */
    protected function buildSearchString(array $parameters): array
    {
        $reverse_search_string = '';

        if ($parameters['reverse']) {
            if (filter_var($parameters['query'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $reverse_search_string = implode('.', array_reverse(explode('.', $parameters['query'])));
            } elseif (filter_var($parameters['query'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $reverse_search_string = unpack('H*hex', inet_pton($parameters['query']));
                $reverse_search_string = implode('.', array_reverse(str_split($reverse_search_string['hex'])));
            } else {
                $parameters['reverse'] = false;
            }

            $reverse_search_string = '%' . $reverse_search_string . '%';
        }

        $needle = idn_to_ascii(trim($parameters['query']), IDNA_NONTRANSITIONAL_TO_ASCII);
        $search_string = ($parameters['wildcard'] ? '%' : '') . $needle . ($parameters['wildcard'] ? '%' : '');
        return array($reverse_search_string, $parameters, $search_string);
    }

    /**
     * Handles SQL mode for MySQL database connection by disabling 'ONLY_FULL_GROUP_BY' if needed.
     *
     * @return string The original SQL mode if modified, or an empty string if no change was needed or not using MySQL.
     */
    protected function handleSqlMode(): string
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
     * Restores the original SQL mode for the MySQL database connection if needed.
     *
     * @param string $originalSqlMode The original SQL mode to be restored.
     * @return void
     */
    protected function restoreSqlMode(string $originalSqlMode): void
    {
        if ($this->db_type === 'mysql' && $originalSqlMode !== '') {
            $this->db->exec("SET SESSION sql_mode = '$originalSqlMode'");
        }
    }
}
