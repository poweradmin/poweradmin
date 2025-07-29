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

namespace Poweradmin\Infrastructure\Database;

/**
 * DbCompat class provides compatibility methods for different database types.
 */
final class DbCompat
{
    /**
     * Mapping of database types to their corresponding substring functions.
     */
    private const SUBSTRING_FUNCTIONS = [
        'sqlite' => 'SUBSTR',
        'default' => 'SUBSTRING'
    ];

    /**
     * Mapping of database types to their corresponding regular expression functions.
     */
    private const REGEXP_FUNCTIONS = [
        'mysql' => 'REGEXP',
        'mysqli' => 'REGEXP',
        'sqlite' => 'GLOB',
        'pgsql' => '~',
        'default' => 'REGEXP'
    ];

    /**
     * Returns the appropriate substring function for the given database type.
     *
     * @param string $db_type The type of database (e.g., "sqlite", "mysql", etc.)
     * @return string The substring function corresponding to the given database type.
     */
    public static function substr(string $db_type): string
    {
        return self::SUBSTRING_FUNCTIONS[$db_type] ?? self::SUBSTRING_FUNCTIONS['default'];
    }

    /**
     * Returns the appropriate regular expression function for the given database type.
     *
     * @param string $db_type The type of database (e.g., "mysql", "sqlite", etc.)
     * @return string The regular expression function corresponding to the given database type.
     */
    public static function regexp(string $db_type): string
    {
        return self::REGEXP_FUNCTIONS[$db_type] ?? self::REGEXP_FUNCTIONS['default'];
    }

    /**
     * Handles SQL mode for MySQL database connection by disabling 'ONLY_FULL_GROUP_BY' if needed.
     *
     * @param object $db The database connection object
     * @param string $db_type The database type
     * @return string The original SQL mode if modified, or an empty string if no change was needed or not using MySQL.
     */
    public static function handleSqlMode(object $db, string $db_type): string
    {
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
        return $originalSqlMode;
    }

    /**
     * Restores the original SQL mode for the MySQL database connection if needed.
     *
     * @param object $db The database connection object
     * @param string $db_type The database type
     * @param string $originalSqlMode The original SQL mode to be restored.
     * @return void
     */
    public static function restoreSqlMode(object $db, string $db_type, string $originalSqlMode): void
    {
        if ($db_type === 'mysql' && $originalSqlMode !== '') {
            $db->exec("SET SESSION sql_mode = '$originalSqlMode'");
        }
    }
}
