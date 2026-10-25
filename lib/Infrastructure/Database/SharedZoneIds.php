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
use Psr\Log\LoggerInterface;
use WeakMap;

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
     * Shared ids and opened names per connection, so a page reads them once. Kept per PDO
     * object and dropped with it; forget() after creating, deleting or re-keying zone rows.
     *
     * @var WeakMap<PDO, array{all?: int[], opened?: array<int, string>}>|null
     */
    private static ?WeakMap $cache = null;

    public static function forget(PDO $db): void
    {
        if (self::$cache !== null) {
            unset(self::$cache[$db]);
        }
    }

    /** @return array{all?: int[], opened?: array<int, string>} */
    private static function cached(PDO $db): array
    {
        self::$cache ??= new WeakMap();

        return self::$cache[$db] ?? [];
    }

    /** @param array{all?: int[], opened?: array<int, string>} $entry */
    private static function store(PDO $db, array $entry): void
    {
        self::$cache ??= new WeakMap();
        self::$cache[$db] = $entry;
    }

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
     * The id of the zones row the id opens (see CanonicalZoneSql::selectByZoneId()), or 0.
     */
    private static function openedRowId(PDO $db, int $zoneId): int
    {
        $stmt = $db->prepare(CanonicalZoneSql::selectByZoneId('id'));
        CanonicalZoneSql::bindZoneId($stmt, $zoneId);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * Before the zones row $rowId is removed: when it is the zone an id opens and another row
     * matches that id, point the API key scopes on the id at zone 0, which matches no zone.
     * Once the row is gone the id opens that other zone, which those keys were never restricted to.
     */
    public static function unscopeApiKeysFromOpenedRow(PDO $db, int $zoneId, int $rowId, LoggerInterface $logger): void
    {
        if (self::opensAnotherRowWithout($db, $zoneId, [$rowId])) {
            self::unscopeApiKeys($db, $zoneId, $logger);
        }
    }

    /**
     * Before the named zones rows $rowIds are removed by a path that keeps the grants on their
     * ids: an id one of them opens that another named row still matches opens that row
     * afterwards, so its API key scopes move to zone 0 and its grants and extra owners go.
     *
     * @param int[] $rowIds
     */
    public static function releaseIdsOpenedByRows(PDO $db, array $rowIds, LoggerInterface $logger): void
    {
        $rowIds = array_values(array_unique(array_map('intval', $rowIds)));
        $zoneIds = [];
        $stmt = $db->prepare("SELECT id, domain_id FROM zones WHERE id = :id AND zone_name IS NOT NULL");
        foreach ($rowIds as $rowId) {
            $stmt->bindValue(':id', $rowId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            // Only a row's canonical id can open it while another row matches the same id
            if (is_array($row)) {
                $zoneIds[(int)($row['domain_id'] ?: $row['id'])] = true;
            }
        }

        foreach (array_keys($zoneIds) as $zoneId) {
            if (!self::opensAnotherRowWithout($db, $zoneId, $rowIds)) {
                continue;
            }
            self::unscopeApiKeys($db, $zoneId, $logger);
            $grants = $db->prepare("DELETE FROM zones_groups WHERE domain_id = :zone_id");
            $grants->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $grants->execute();
            $owners = $db->prepare("DELETE FROM zones WHERE zone_name IS NULL AND domain_id = :zone_id");
            $owners->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $owners->execute();
            $logger->warning('Zone id {zone} opens another zone once its row is removed; its group grants and extra owners were dropped', ['zone' => $zoneId]);
        }
    }

    /**
     * Whether the id opens one of $rowIds now and, once they are gone, another named row (the
     * WHERE of CanonicalZoneSql::selectByZoneId() without them).
     *
     * @param int[] $rowIds
     */
    private static function opensAnotherRowWithout(PDO $db, int $zoneId, array $rowIds): bool
    {
        if (!in_array(self::openedRowId($db, $zoneId), $rowIds, true)) {
            return false;
        }

        $stmt = $db->prepare("SELECT id FROM zones WHERE (id = :id OR domain_id = :did) AND zone_name IS NOT NULL");
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':did', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return array_diff(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), $rowIds) !== [];
    }

    private static function unscopeApiKeys(PDO $db, int $zoneId, LoggerInterface $logger): void
    {
        $scoped = self::pointApiKeyScopesAtNoZone($db, $zoneId);
        if ($scoped === []) {
            return;
        }
        $logger->warning('API keys {keys}: zone scope {zone} named a removed zone whose id another zone shares and now matches no zone; re-select the zone on the key', [
            'keys' => implode(', ', $scoped),
            'zone' => $zoneId,
        ]);
    }

    /**
     * Point the API key scopes on $zoneId at zone 0, which matches no zone, and return the
     * keys that had one. Deleting the rows instead would leave a key with none, which means unrestricted.
     *
     * @return int[]
     */
    public static function pointApiKeyScopesAtNoZone(PDO $db, int $zoneId): array
    {
        $stmt = $db->prepare("SELECT api_key_id, zone_id FROM api_key_zones WHERE zone_id IN (0, :zone_id)");
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $scoped = [];
        $unmapped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int)$row['zone_id'] === 0) {
                $unmapped[(int)$row['api_key_id']] = true;
            } else {
                $scoped[] = (int)$row['api_key_id'];
            }
        }
        if ($scoped === []) {
            return [];
        }
        sort($scoped);

        // One statement per key, so no failure leaves a key with no scope row, which means unrestricted
        $update = $db->prepare("UPDATE api_key_zones SET zone_id = 0 WHERE api_key_id = :key_id AND zone_id = :zone_id");
        $delete = $db->prepare("DELETE FROM api_key_zones WHERE api_key_id = :key_id AND zone_id = :zone_id");
        foreach ($scoped as $keyId) {
            $stmt = isset($unmapped[$keyId]) ? $delete : $update;
            $stmt->bindValue(':key_id', $keyId, PDO::PARAM_INT);
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $scoped;
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
        $entry = self::cached($db);
        if (!isset($entry['all'])) {
            $stmt = $db->prepare(self::sharedIdsSql());
            $stmt->execute();
            $entry['all'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            self::store($db, $entry);
        }

        return $entry['all'];
    }

    /**
     * The name of the zone each shared id opens, keyed by that id. A list built from the
     * backend's zone list keeps only these zones for a shared id.
     *
     * @return array<int, string>
     */
    public static function openedNames(PDO $db): array
    {
        $entry = self::cached($db);
        if (isset($entry['opened'])) {
            return $entry['opened'];
        }

        $names = [];
        foreach (self::all($db) as $sharedId) {
            $stmt = $db->prepare(CanonicalZoneSql::selectByZoneId('zone_name'));
            CanonicalZoneSql::bindZoneId($stmt, $sharedId);
            $stmt->execute();
            $names[$sharedId] = (string)$stmt->fetchColumn();
        }
        $entry = self::cached($db);
        $entry['opened'] = $names;
        self::store($db, $entry);

        return $names;
    }

    /**
     * Whether a zone from the backend's zone list is the one its id opens.
     *
     * @param array<int, string> $openedNames From openedNames()
     * @param array<string, mixed> $zone A zone with id and name (with or without trailing dot)
     */
    public static function isOpenedZone(array $openedNames, array $zone): bool
    {
        $zoneId = (int)($zone['id'] ?? 0);

        return !isset($openedNames[$zoneId]) || $openedNames[$zoneId] === rtrim((string)($zone['name'] ?? ''), '.');
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

        return array_values(array_intersect(self::all($db), array_map('intval', $zoneIds)));
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
