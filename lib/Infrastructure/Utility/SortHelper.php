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
     * Get the zone sort order based on the database type.
     *
     * @param string $table
     * @param mixed $dbType
     * @param string $direction
     * @return string
     */
    public static function getZoneSortOrder(string $table, string $dbType, string $direction = 'ASC'): string
    {
        $nameField = "$table.name";
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }

        $naturalSort = match ($dbType) {
            'mysql', 'mysqli' => "CASE WHEN {$nameField} LIKE '%.arpa' THEN 1 ELSE 0 END DESC, CAST(REGEXP_SUBSTR({$nameField}, '^[0-9]+') AS UNSIGNED) $direction, $nameField $direction",
            'sqlite', 'sqlite3' => "CASE WHEN {$nameField} LIKE '%.arpa' THEN 1 ELSE 0 END DESC, CAST(substr({$nameField}, 1, instr({$nameField}, '.') - 1) AS INTEGER) $direction, $nameField {$direction}",
            'pgsql' => "CASE WHEN {$nameField} LIKE '%.arpa' THEN 1 ELSE 0 END DESC, CAST((REGEXP_MATCHES({$nameField}, '^[0-9]+'))[1] AS INTEGER) $direction, $nameField $direction",
            default => "$nameField $direction",
        };

        return $naturalSort;
    }

    /**
     * Get the record sort order based on the database type.
     *
     * @param string $table
     * @param mixed $dbType
     * @param string $direction
     * @return string
     */
    public static function getRecordSortOrder(string $table, string $dbType, string $direction = 'ASC'): string
    {
        $nameField = "$table.name";
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }

        $naturalSort = match ($dbType) {
            'mysql', 'mysqli' => "CAST(REGEXP_SUBSTR($nameField, '^[0-9]+') AS UNSIGNED) $direction, $nameField $direction",
            'sqlite', 'sqlite3' => "CAST(substr($nameField, 1, instr($nameField, '.') - 1) AS INTEGER) $direction, $nameField $direction",
            'pgsql' => "CAST((REGEXP_MATCHES($nameField, '^[0-9]+'))[1] AS INTEGER) $direction, $nameField $direction",
            default => "$nameField $direction",
        };

        return $naturalSort;
    }
}