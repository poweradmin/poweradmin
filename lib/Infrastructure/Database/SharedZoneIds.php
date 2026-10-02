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
use Poweradmin\Domain\Database\CanonicalZoneSql;

/**
 * Ownership under a zone id collision in API backend mode.
 *
 * A zone this application created and a zone migrated from SQL mode can share a canonical
 * id. The id then resolves to one of them (see CanonicalZoneSql::selectByZoneId()), but the
 * extra-owner rows and group grants keyed by that id belong to both, so they would let the
 * other zone's owners act on it. For a shared id only the direct owner of the row it
 * resolves to counts.
 */
final class SharedZoneIds
{
    /**
     * Whether more than one named zone has this canonical id.
     */
    public static function isShared(PDO $db, int $zoneId): bool
    {
        // The canonical id spelled out, so the id and domain_id indexes apply
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM zones
             WHERE zone_name IS NOT NULL
               AND (domain_id = :did OR (id = :id AND (domain_id IS NULL OR domain_id = 0)))"
        );
        $stmt->bindValue(':did', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn() > 1;
    }

    /**
     * The direct owner of the zone a shared id resolves to, or null when it has none.
     */
    public static function resolvedOwner(PDO $db, int $zoneId): ?int
    {
        $stmt = $db->prepare(CanonicalZoneSql::selectByZoneId('owner'));
        CanonicalZoneSql::bindZoneId($stmt, $zoneId);
        $stmt->execute();
        $owner = $stmt->fetchColumn();

        return $owner === false || $owner === null || (int)$owner <= 0 ? null : (int)$owner;
    }

    /**
     * Whether the user is the direct owner of the zone a shared id resolves to.
     */
    public static function ownsResolvedZone(PDO $db, int $userId, int $zoneId): bool
    {
        return self::resolvedOwner($db, $zoneId) === $userId;
    }

    /**
     * Every shared canonical id.
     *
     * @return int[]
     */
    public static function all(PDO $db): array
    {
        $stmt = $db->prepare(self::sharedIdsSql());
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * SQL selecting every shared canonical id, for queries that filter in SQL.
     */
    public static function sharedIdsSql(): string
    {
        $canonicalId = CanonicalZoneSql::canonicalIdColumn('zs', true);

        // Wrapped in a derived table so MySQL accepts it next to another read of zones
        return "SELECT shared_id FROM (SELECT $canonicalId AS shared_id FROM zones zs
                 WHERE zs.zone_name IS NOT NULL GROUP BY $canonicalId HAVING COUNT(*) > 1) shared_zone_ids";
    }

    /**
     * The ids among $zoneIds that more than one named zone shares.
     *
     * @param int[] $zoneIds
     * @return int[]
     */
    public static function sharedAmong(PDO $db, array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }

        $canonicalId = CanonicalZoneSql::canonicalIdColumn('', true);
        $idList = implode(',', array_map('intval', $zoneIds));
        $stmt = $db->prepare(
            "SELECT $canonicalId FROM zones
             WHERE zone_name IS NOT NULL AND $canonicalId IN ($idList)
             GROUP BY $canonicalId HAVING COUNT(*) > 1"
        );
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The user's owned zone ids without the shared ones whose resolved zone they do not own directly.
     *
     * @param int[] $zoneIds
     * @return int[]
     */
    public static function filterOwned(PDO $db, int $userId, array $zoneIds): array
    {
        $shared = self::sharedAmong($db, $zoneIds);

        return array_values(array_filter(
            $zoneIds,
            static fn(int $zoneId): bool => !in_array($zoneId, $shared, true) || self::ownsResolvedZone($db, $userId, $zoneId)
        ));
    }
}
