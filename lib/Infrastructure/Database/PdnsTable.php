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
 * Enum representing all valid PowerDNS table names
 *
 * This enum provides compile-time safety for table names, making it impossible
 * to use invalid table names and providing IDE autocomplete support.
 *
 * @package Poweradmin\Infrastructure\Database
 * @since 3.0.0
 */
enum PdnsTable: string
{
    case DOMAINS = 'domains';
    case RECORDS = 'records';
    case SUPERMASTERS = 'supermasters';
    case COMMENTS = 'comments';
    case DOMAINMETADATA = 'domainmetadata';
    case CRYPTOKEYS = 'cryptokeys';
    case TSIGKEYS = 'tsigkeys';

    /**
     * Get the full table name with optional database prefix
     *
     * @param string|null $prefix Database prefix (e.g., 'pdns_production')
     * @return string Full table name with prefix if provided
     */
    public function getFullName(?string $prefix = null): string
    {
        return $prefix ? $prefix . '.' . $this->value : $this->value;
    }

    /**
     * Get all available table names as array
     *
     * @return array<string> Array of all table names
     */
    public static function getAllTableNames(): array
    {
        return array_map(fn(self $table) => $table->value, self::cases());
    }

    /**
     * Check if a string is a valid table name
     *
     * @param string $tableName Table name to validate
     * @return bool True if valid table name
     */
    public static function isValidTableName(string $tableName): bool
    {
        return self::tryFrom($tableName) !== null;
    }

    /**
     * Get table enum from string with validation
     *
     * @param string $tableName Table name string
     * @return self PdnsTable enum
     * @throws \InvalidArgumentException If table name is invalid
     */
    public static function fromString(string $tableName): self
    {
        $table = self::tryFrom($tableName);
        if ($table === null) {
            throw new \InvalidArgumentException("Table name not allowed for prefixing: $tableName");
        }
        return $table;
    }
}
