<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

use Closure;
use LogicException;
use Poweradmin\Domain\Enum\SortDirection;

/**
 * Per-database ORDER BY clauses that sort zone and record names naturally instead of
 * lexically, and the ORDER BY of API lists sorted in SQL.
 */
final class SortHelper
{
    /**
     * ORDER BY clause that sorts the name column naturally for the given driver.
     */
    public static function getNaturalSortOrder(string $table, string $dbType, string $direction = 'ASC'): string
    {
        return self::naturalSortOrder("$table.name", $dbType, $direction);
    }

    /**
     * ORDER BY clause that sorts any name column naturally, for tables whose
     * name column is not called name (e.g. zones.zone_name in API mode).
     */
    public static function naturalSortOrder(string $nameField, string $dbType, string $direction = 'ASC'): string
    {
        $direction = SortDirection::fromRequest($direction)->value;

        $naturalSort = match ($dbType) {
            'mysql', 'mysqli', 'sqlite' => "$nameField+0<>0 $direction, $nameField+0 $direction, $nameField $direction",
            'pgsql' => "SUBSTRING($nameField FROM '\.arpa$') $direction, LENGTH(SUBSTRING($nameField FROM '^[0-9]+')) $direction, $nameField $direction",
            default => "$nameField $direction",
        };

        return $naturalSort;
    }

    /**
     * ORDER BY for an API list sorted in SQL, from the keys ListSort::fields() returns.
     * Each allowed field maps to a fixed column, or to a closure that gets ASC or DESC
     * and returns the clause (e.g. a natural name order), so request input never
     * reaches the SQL. The tiebreaker comes last so pages stay stable when sort values
     * repeat; without sort keys the list keeps its default order.
     *
     * @param list<array{field: string, desc: bool}> $sort
     * @param array<string, string|Closure(string): string> $columns
     */
    public static function orderBy(array $sort, array $columns, string $tiebreaker, string $default): string
    {
        if ($sort === []) {
            return $default;
        }

        $clauses = [];
        foreach ($sort as $key) {
            $column = $columns[$key['field']] ?? throw new LogicException("Unknown sort field '{$key['field']}'");
            $direction = $key['desc'] ? 'DESC' : 'ASC';
            $clauses[] = $column instanceof Closure ? $column($direction) : "$column $direction";
        }
        $clauses[] = $tiebreaker;

        return implode(', ', $clauses);
    }
}
