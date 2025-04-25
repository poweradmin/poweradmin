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

/**
 * Class ReverseDomainNaturalSorting
 *
 * Provides specialized natural sorting functionality for reverse DNS domains
 * with support for MySQL, PostgreSQL and SQLite.
 *
 * This class specifically handles the special case of reverse domain sorting,
 * ensuring IP addresses in reverse notation are sorted properly.
 */
class ReverseDomainNaturalSorting
{
    /**
     * Get the natural sort order SQL clause for reverse DNS domains based on the database type.
     *
     * @param string $field The full field name to sort (e.g., "table.name")
     * @param string $dbType The database type ('mysql', 'mysqli', 'pgsql', 'sqlite')
     * @param string $direction Sort direction ('ASC' or 'DESC')
     * @return string SQL ORDER BY clause for reverse domain natural sorting
     */
    public function getNaturalSortOrder(string $field, string $dbType, string $direction = 'ASC'): string
    {
        // Normalize direction
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }

        // Generate database-specific natural sort
        return match ($dbType) {
            // MySQL and SQLite can use the same approach with arithmetic operators
            'mysql', 'mysqli', 'sqlite' => "$field+0<>0 $direction, $field+0 $direction, $field $direction",

            // PostgreSQL needs a different approach using string functions
            'pgsql' => "SUBSTRING($field FROM '\.arpa$') $direction, LENGTH(SUBSTRING($field FROM '^[0-9]+')) $direction, $field $direction",

            // Fallback for unknown database types
            default => "$field $direction",
        };
    }
}
