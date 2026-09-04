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

use InvalidArgumentException;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Domain\Enum\SortDirection;

class TableNameService
{
    private ?string $pdnsDbName;

    public function __construct(ConfigurationInterface $config)
    {
        $this->pdnsDbName = $config->get('database', 'pdns_db_name');
    }

    /**
     * Get table name with prefix using enum (preferred method)
     *
     * @param PdnsTable $table Table enum
     * @return string Full table name with prefix if configured
     */
    public function getTable(PdnsTable $table): string
    {
        return $table->getFullName($this->pdnsDbName);
    }

    /**
     * Get multiple table names at once using enums
     *
     * @param PdnsTable ...$tables Variable number of table enums
     * @return array<string> Array of full table names
     */
    public function getTables(PdnsTable ...$tables): array
    {
        return array_map(
            fn(PdnsTable $table) => $table->getFullName($this->pdnsDbName),
            $tables
        );
    }

    /**
     * Only ever returns a value present in $allowedColumns, so the result is safe
     * to interpolate into ORDER BY.
     *
     * @psalm-taint-escape sql
     */
    public function validateOrderBy(string $column, array $allowedColumns): string
    {
        if (!in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException("Invalid ORDER BY column: $column");
        }

        return $column;
    }

    /**
     * Only ever returns 'ASC' or 'DESC', so the result is safe to interpolate.
     *
     * @psalm-taint-escape sql
     */
    public function validateDirection(string $direction): string
    {
        $normalized = SortDirection::tryFrom(strtoupper($direction));

        if ($normalized === null) {
            throw new InvalidArgumentException("Invalid sort direction: $direction");
        }

        return $normalized->value;
    }

    public function validateLimit(int $limit, int $maxLimit = 10000): int
    {
        if ($limit < 0) {
            throw new InvalidArgumentException("LIMIT cannot be negative: $limit");
        }

        if ($limit > $maxLimit) {
            throw new InvalidArgumentException("LIMIT too large: $limit (max: $maxLimit)");
        }

        return $limit;
    }

    public function validateOffset(int $offset): int
    {
        if ($offset < 0) {
            throw new InvalidArgumentException("OFFSET cannot be negative: $offset");
        }

        return $offset;
    }
}
