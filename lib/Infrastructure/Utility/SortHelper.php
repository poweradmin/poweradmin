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
            'mysql', 'mysqli', 'sqlite' => "$nameField+0<>0 $direction, $nameField+0 $direction, $nameField $direction",
            'pgsql' => "SUBSTRING($nameField FROM '\.arpa$') $direction, LENGTH(SUBSTRING($nameField FROM '^[0-9]+')) $direction, $nameField $direction",
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
            'mysql', 'mysqli', 'sqlite' => "$nameField+0<>0 $direction, $nameField+0 $direction, $nameField $direction",
            'pgsql' => "SUBSTRING($nameField FROM '\.arpa$') $direction, LENGTH(SUBSTRING($nameField FROM '^[0-9]+')) $direction, $nameField $direction",
            default => "$nameField $direction",
        };

        return $naturalSort;
    }
}
