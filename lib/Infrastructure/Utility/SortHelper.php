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
    private const IPV4_ARPA_SUFFIX = '.in-addr.arpa';
    private const IP_LABELS = 4;
    private const NON_NUMERIC_LABEL = 1000000;

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
     * Tell whether a zone is an IPv4 reverse zone, whose records sort by IP address.
     * ip6.arpa is left out: SQLite has no REVERSE() to build the nibble key.
     */
    public static function isIpv4ReverseZone(string $zoneName): bool
    {
        $zoneName = strtolower(rtrim($zoneName, '.'));

        return $zoneName === 'in-addr.arpa' || str_ends_with($zoneName, self::IPV4_ARPA_SUFFIX);
    }

    /**
     * Tell whether a zone's records sort by IP address: an IPv4 reverse zone whose name holds
     * only plain DNS characters, since the name goes into the SQL text. SQL and API mode both
     * gate on this, so they fall back to the natural order together.
     */
    public static function sortsByAddress(string $zoneName): bool
    {
        $zoneName = strtolower(rtrim($zoneName, '.'));

        return self::isIpv4ReverseZone($zoneName)
            && preg_match('/^[a-z0-9_*\/-]+(\.[a-z0-9_*\/-]+)*$/', $zoneName) === 1;
    }

    /**
     * How many address labels a record name can still carry below the zone: 3 under
     * 192.in-addr.arpa, 1 under 2.168.192.in-addr.arpa. Always at least one, so a
     * classless zone such as 0/25.2.0.192.in-addr.arpa still sorts its host labels.
     */
    public static function reverseZoneIpPositions(string $zoneName): int
    {
        $zoneName = strtolower(rtrim($zoneName, '.'));
        if ($zoneName === 'in-addr.arpa') {
            return self::IP_LABELS;
        }
        if (!str_ends_with($zoneName, self::IPV4_ARPA_SUFFIX)) {
            return 1;
        }

        return max(1, self::IP_LABELS - substr_count(substr($zoneName, 0, -strlen(self::IPV4_ARPA_SUFFIX)), '.') - 1);
    }

    /**
     * ORDER BY clause that sorts the record names of an in-addr.arpa zone in IP order.
     * The zone's own labels are the same on every record, so they are cut off and the
     * labels below it are compared as numbers, right to left: 10.0.2.5 comes before
     * 10.0.10.5. The apex (nothing left) comes first, labels that are not numbers come
     * last, and the natural name sort breaks ties. Evaluated per row, so it is a filesort,
     * as the natural sort already is.
     */
    public static function getReverseRecordNameSortOrder(string $table, string $dbType, string $direction = 'ASC', string $zoneName = 'in-addr.arpa'): string
    {
        $natural = self::getNaturalSortOrder($table, $dbType, $direction);
        $zoneName = strtolower(rtrim($zoneName, '.'));
        if (!in_array($dbType, ['mysql', 'mysqli', 'pgsql', 'sqlite'], true) || !self::sortsByAddress($zoneName)) {
            return $natural;
        }

        $direction = SortDirection::fromRequest($direction)->value;
        $below = self::belowZoneExpression("$table.name", $zoneName, $dbType);

        $terms = [];
        for ($position = 1; $position <= self::reverseZoneIpPositions($zoneName); $position++) {
            $terms[] = self::labelNumberExpression(self::labelFromRightExpression($below, $position, $dbType), $dbType) . " $direction";
        }
        $terms[] = $natural;

        return implode(', ', $terms);
    }

    /**
     * The PHP twin of the reverse record order, for API mode where records are sorted in PHP.
     *
     * @return list<int> -1 for a missing label, 1000000 for a label that is not a number
     */
    public static function reverseRecordNameKey(string $name, string $zoneName = 'in-addr.arpa'): array
    {
        $name = strtolower(rtrim($name, '.'));
        $zoneName = strtolower(rtrim($zoneName, '.'));
        $below = str_ends_with($name, '.' . $zoneName) ? substr($name, 0, -strlen($zoneName) - 1) : '';
        $labels = $below === '' ? [] : array_reverse(explode('.', $below));

        $key = [];
        for ($i = 0; $i < self::reverseZoneIpPositions($zoneName); $i++) {
            $label = $labels[$i] ?? '';
            $digits = preg_match('/^[0-9]{1,6}/', $label, $matches) === 1 ? $matches[0] : null;
            $key[] = match (true) {
                $label === '' => -1,
                $digits !== null => (int)$digits,
                default => self::NON_NUMERIC_LABEL,
            };
        }

        return $key;
    }

    /**
     * Compare two record names of an in-addr.arpa zone in IP order, as the SQL order does.
     */
    public static function compareReverseRecordNames(string $a, string $b, string $zoneName = 'in-addr.arpa'): int
    {
        return self::reverseRecordNameKey($a, $zoneName) <=> self::reverseRecordNameKey($b, $zoneName);
    }

    /**
     * The record name without the zone suffix, '' for the apex or a name outside the zone.
     */
    private static function belowZoneExpression(string $field, string $zoneName, string $dbType): string
    {
        $length = in_array($dbType, ['mysql', 'mysqli'], true) ? 'CHAR_LENGTH' : 'LENGTH';
        $suffix = '.' . $zoneName;
        $suffixLength = strlen($suffix);

        return "CASE WHEN LOWER(SUBSTR($field, $length($field) - $suffixLength + 1)) = '$suffix' THEN SUBSTR($field, 1, $length($field) - $suffixLength) ELSE '' END";
    }

    /**
     * The label at $position counting from the right of a dotted string, '' when absent.
     */
    private static function labelFromRightExpression(string $dotted, int $position, string $dbType): string
    {
        if ($dbType === 'pgsql') {
            return "REVERSE(SPLIT_PART(REVERSE($dotted), '.', $position))";
        }
        if ($dbType === 'sqlite') {
            // No split function: rtrim with the string's own non-dot characters stops at the last dot.
            $rest = $dotted;
            for ($i = 1; $i < $position; $i++) {
                $rest = "RTRIM(RTRIM($rest, REPLACE($rest, '.', '')), '.')";
            }

            return "SUBSTR($rest, LENGTH(RTRIM($rest, REPLACE($rest, '.', ''))) + 1)";
        }

        return "SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT('....', $dotted), '.', -$position), '.', 1)";
    }

    private static function labelNumberExpression(string $label, string $dbType): string
    {
        $nonNumeric = self::NON_NUMERIC_LABEL;
        [$isNumber, $number] = match ($dbType) {
            'pgsql' => ["$label ~ '^[0-9]'", "CAST(SUBSTRING($label FROM '^[0-9]{1,6}') AS INTEGER)"],
            'sqlite' => ["$label GLOB '[0-9]*'", "CAST(SUBSTR($label, 1, 6) AS INTEGER)"],
            default => ["$label REGEXP '^[0-9]'", "CAST(SUBSTR($label, 1, 6) AS UNSIGNED)"],
        };

        return "CASE WHEN $label = '' THEN -1 WHEN $isNumber THEN $number ELSE $nonNumeric END";
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
