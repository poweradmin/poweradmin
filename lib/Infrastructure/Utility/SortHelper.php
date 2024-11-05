<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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

namespace Poweradmin\Infrastructure\Utility;

class SortHelper
{
    /**
     * Get the natural sort for the given database type
     *
     * @param string $table
     * @param mixed $db_type
     * @param string $direction
     * @return string
     */
    public static function getNaturalSort(string $table, string $db_type, string $direction = 'ASC'): string
    {
        $name_field = "$table.name";
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            $direction = 'ASC';
        }

        $natural_sort = match ($db_type) {
            'mysql', 'mysqli' => "CAST(REGEXP_SUBSTR($name_field, '[0-9]+') AS UNSIGNED) $direction, $name_field $direction",
            'sqlite', 'sqlite3' => "CAST(substr($name_field, 1, instr($name_field, '.') - 1) AS INTEGER) $direction, $name_field $direction",
            'pgsql' => "CAST((REGEXP_MATCHES($name_field, '[0-9]+'))[1] AS INTEGER) $direction, $name_field $direction",
            default => "$name_field $direction",
        };

        return $natural_sort;
    }
}