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

namespace Poweradmin\Domain\Database;

use PDO;
use PDOStatement;

/**
 * Resolves which zones row a Poweradmin zone ID refers to.
 *
 * A zone ID reaching the infrastructure layer is zones.domain_id on installs migrated from
 * SQL mode and zones.id on zones this application created, so both have to be matched. The
 * two id spaces overlap, so a single ID can match one row by id and a different row by
 * domain_id - picking the wrong one updates the wrong zone. Every resolver must therefore
 * agree on the same preference, which is why this lives in one place.
 *
 * Known limit: an extra-ownership row (NULL zone_name) is keyed only by canonical id, so
 * under that collision no query can tell which of the two zones it belongs to.
 */
final class CanonicalZoneSql
{
    /**
     * SQL expression for a zones row's canonical id, the value API mode hands to callers.
     *
     * The PHP form of this rule is `domain_id ?: id`, which treats 0 as absent. A bare
     * COALESCE does not: it skips NULL only, so a row stranded at domain_id = 0 resolves to
     * 0 rather than to its own id. NULLIF folds that 0 into NULL first, which is what keeps
     * this expression in step with the PHP rule.
     *
     * The fallback to id belongs to the API backend, where zones is the source of truth
     * (BackendCapabilitiesInterface::allocatesZoneIdsLocally()). In SQL mode domain_id is a
     * foreign key into domains and always populated, and zones.id is an unrelated id space,
     * so callers there pass false and get the bare column; the fallback would point at
     * another zone.
     *
     * Bind ids compared against this with PDO::PARAM_INT. An expression carries none of
     * the column's type affinity, so SQLite compares a string-bound id as text and matches
     * nothing, where the bare column would have coerced it.
     *
     * @param string $alias Table alias or name without the dot, e.g. 'z' or 'zones'; '' for none
     * @param bool $rowIdFallback Whether zones.id may stand in for a missing domain_id (API backend)
     */
    public static function canonicalIdColumn(string $alias, bool $rowIdFallback): string
    {
        $prefix = $alias === '' ? '' : rtrim($alias, '.') . '.';

        if (!$rowIdFallback) {
            return "{$prefix}domain_id";
        }

        return "COALESCE(NULLIF({$prefix}domain_id, 0), {$prefix}id)";
    }

    /**
     * JOIN onto the table that names a zone, for a query keyed on $zoneIdColumn.
     *
     * When the zones table is the source of truth (BackendCapabilitiesInterface::allocatesZoneIdsLocally())
     * it carries zone_name; otherwise the name lives in the PowerDNS domains table, which the
     * caller resolves through TableNameService.
     *
     * @param bool $zonesTableIsCanonical Whether zone ids are allocated from the zones table
     * @param string $zoneIdColumn Qualified zone id column of the driving table, e.g. 'log_zones.zone_id'
     * @param string $domainsTable Resolved PowerDNS domains table name
     * @return array{join: string, name: string} The JOIN clause and the qualified name column
     */
    public static function zoneNameJoin(bool $zonesTableIsCanonical, string $zoneIdColumn, string $domainsTable): array
    {
        if ($zonesTableIsCanonical) {
            return [
                'join' => 'INNER JOIN zones ON ' . self::canonicalIdColumn('zones', true) . " = $zoneIdColumn",
                'name' => 'zones.zone_name',
            ];
        }

        return [
            'join' => "INNER JOIN $domainsTable ON $domainsTable.id = $zoneIdColumn",
            'name' => "$domainsTable.name",
        ];
    }

    /**
     * SELECT that resolves a zone ID to exactly one zones row.
     *
     * Placeholder ownership rows (zone_name IS NULL) never win. Among real rows the order is:
     * a row whose id and domain_id both match, then a domain_id match, then an id match.
     * domain_id outranks id because that is the identifier API mode hands to callers - see
     * ApiDnsBackendProvider::getZones(), which emits `domain_id ?: id`.
     *
     * Bind with bindZoneId(). The caller supplies the column list it needs.
     *
     * @param string $columns SELECT-list columns, e.g. "id, zone_name, zone_type"
     */
    public static function selectByZoneId(string $columns): string
    {
        return "SELECT $columns
             FROM zones
             WHERE (id = :id OR domain_id = :did) AND zone_name IS NOT NULL
             ORDER BY CASE
                 WHEN id = :self_id AND domain_id = :self_did THEN 0
                 WHEN domain_id = :pref_did THEN 1
                 ELSE 2
             END
             LIMIT 1";
    }

    /**
     * Bind every placeholder selectByZoneId() declares. PDO will not reuse one named
     * placeholder across positions, hence the repetition.
     */
    public static function bindZoneId(PDOStatement $stmt, int $zoneId): void
    {
        foreach ([':id', ':did', ':self_id', ':self_did', ':pref_did'] as $param) {
            $stmt->bindValue($param, $zoneId, PDO::PARAM_INT);
        }
    }
}
