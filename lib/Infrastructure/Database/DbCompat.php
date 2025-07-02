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
     * Mapping of database types to their corresponding current timestamp functions.
     */
    private const NOW_FUNCTIONS = [
        'sqlite' => "datetime('now')",
        'default' => 'NOW()'
    ];

    /**
     * Mapping of database types to their boolean true values.
     */
    private const BOOL_TRUE = [
        'sqlite' => '1',
        'default' => 'TRUE'
    ];

    /**
     * Mapping of database types to their boolean false values.
     */
    private const BOOL_FALSE = [
        'sqlite' => '0',
        'default' => 'FALSE'
    ];

    /**
     * Mapping of database types to their date subtraction functions.
     */
    private const DATE_SUBTRACT_FUNCTIONS = [
        'mysql' => 'DATE_SUB(NOW(), INTERVAL :seconds SECOND)',
        'mysqli' => 'DATE_SUB(NOW(), INTERVAL :seconds SECOND)',
        'sqlite' => "datetime('now', '-:seconds seconds')",
        'pgsql' => "NOW() - INTERVAL ':seconds seconds'",
        'default' => 'DATE_SUB(NOW(), INTERVAL :seconds SECOND)'
    ];

    /**
     * Mapping of database types to their string concatenation functions.
     */
    private const CONCAT_FUNCTIONS = [
        'mysql' => 'CONCAT',
        'mysqli' => 'CONCAT',
        'sqlite' => '||',
        'pgsql' => 'CONCAT',
        'default' => 'CONCAT'
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
     * Returns the appropriate current timestamp function for the given database type.
     *
     * @param string $db_type The type of database (e.g., "mysql", "sqlite", etc.)
     * @return string The current timestamp function corresponding to the given database type.
     */
    public static function now(string $db_type): string
    {
        return self::NOW_FUNCTIONS[$db_type] ?? self::NOW_FUNCTIONS['default'];
    }

    /**
     * Returns the appropriate boolean true value for the given database type.
     *
     * @param string $db_type The type of database (e.g., "mysql", "sqlite", etc.)
     * @return string The boolean true value corresponding to the given database type.
     */
    public static function boolTrue(string $db_type): string
    {
        return self::BOOL_TRUE[$db_type] ?? self::BOOL_TRUE['default'];
    }

    /**
     * Returns the appropriate boolean false value for the given database type.
     *
     * @param string $db_type The type of database (e.g., "mysql", "sqlite", etc.)
     * @return string The boolean false value corresponding to the given database type.
     */
    public static function boolFalse(string $db_type): string
    {
        return self::BOOL_FALSE[$db_type] ?? self::BOOL_FALSE['default'];
    }

    /**
     * Returns the appropriate date subtraction expression for the given database type.
     *
     * @param string $db_type The type of database (e.g., "mysql", "sqlite", etc.)
     * @param int $seconds The number of seconds to subtract from current time
     * @return string The date subtraction expression corresponding to the given database type.
     */
    public static function dateSubtract(string $db_type, int $seconds): string
    {
        $template = self::DATE_SUBTRACT_FUNCTIONS[$db_type] ?? self::DATE_SUBTRACT_FUNCTIONS['default'];
        return str_replace(':seconds', (string) $seconds, $template);
    }

    /**
     * Returns the appropriate string concatenation function for the given database type.
     *
     * @param string $db_type The type of database (e.g., "mysql", "sqlite", etc.)
     * @param array $values The values to concatenate
     * @return string The concatenation expression corresponding to the given database type.
     */
    public static function concat(string $db_type, array $values): string
    {
        $func = self::CONCAT_FUNCTIONS[$db_type] ?? self::CONCAT_FUNCTIONS['default'];

        if ($db_type === 'sqlite') {
            // SQLite uses || operator for concatenation
            return implode(' || ', $values);
        } else {
            // MySQL, PostgreSQL use CONCAT function
            return $func . '(' . implode(', ', $values) . ')';
        }
    }
}
