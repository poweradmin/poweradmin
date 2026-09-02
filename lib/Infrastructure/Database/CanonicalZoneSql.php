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

namespace Poweradmin\Infrastructure\Database;

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
    private static bool $rowIdFallback = true;

    /**
     * Enable the zones.id fallback only for the API backend. In SQL mode domain_id is always
     * populated and zones.id is an unrelated id space, so the fallback must never fire there.
     * Set once at bootstrap from the configured backend.
     */
    public static function setRowIdFallback(bool $enabled): void
    {
        self::$rowIdFallback = $enabled;
    }

    /**
     * SQL expression for a zones row's canonical id, the value API mode hands to callers.
     *
     * The PHP form of this rule is `domain_id ?: id`, which treats 0 as absent. A bare
     * COALESCE does not: it skips NULL only, so a row stranded at domain_id = 0 resolves to
     * 0 rather than to its own id. NULLIF folds that 0 into NULL first, which is what keeps
     * this expression in step with the PHP rule.
     *
     * Note the fallback to id assumes API mode, where zones is the source of truth. In SQL
     * mode domain_id is a foreign key into domains and is always populated, so the fallback
     * never fires; it must never be used to repair a SQL-mode row, because the two id spaces
     * overlap and id would point at an unrelated zone.
     *
     * @param string $alias Table alias or name without the dot, e.g. 'z' or 'zones'
     */
    public static function canonicalIdColumn(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : rtrim($alias, '.') . '.';

        if (!self::$rowIdFallback) {
            return "{$prefix}domain_id";
        }

        return "COALESCE(NULLIF({$prefix}domain_id, 0), {$prefix}id)";
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
