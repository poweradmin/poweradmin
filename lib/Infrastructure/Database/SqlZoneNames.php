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

/**
 * Keeps one zones row per zone named after its PowerDNS domain under the SQL backend.
 *
 * The SQL backend reads names from domains and does not need zones.zone_name, but a later
 * switch to the API backend finds zones only by that name: an unnamed zone would come back
 * as a new, ownerless row. One row carries the name, because the zone_name index is unique
 * and a zone has one row per owner. Type and master are left to the API sync, which reads
 * them fresh.
 */
final class SqlZoneNames
{
    /**
     * After an owner row is deleted: keep the zone named, and keep an ownerless named row
     * while group grants or API key restrictions still use the zone's id, as a zone created
     * for groups only has. Without it a switch to the API backend gives the zone a new id.
     */
    public static function ensureNamedAfterOwnerRemoval(PDO $db, string $domainsTable, int $domainId): void
    {
        $stmt = $db->prepare(
            "SELECT 1 FROM zones_groups WHERE domain_id = :groups
             UNION ALL SELECT 1 FROM api_key_zones WHERE zone_id = :keys"
        );
        $stmt->bindValue(':groups', $domainId, PDO::PARAM_INT);
        $stmt->bindValue(':keys', $domainId, PDO::PARAM_INT);
        $stmt->execute();

        self::ensureNamed($db, $domainsTable, $domainId, $stmt->fetchColumn() !== false);
    }

    /**
     * Make one of the zone's rows carry its exact PowerDNS name, correcting a stale name.
     *
     * @param string $domainsTable The PowerDNS domains table, prefixed when pdns_db_name is set
     * @param bool $createRow Add an ownerless row for a zone that has none, so grants keyed by
     *                        its id survive a switch to the API backend
     */
    public static function ensureNamed(PDO $db, string $domainsTable, int $domainId, bool $createRow = false): void
    {
        $stmt = $db->prepare("SELECT name FROM $domainsTable WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $name = $stmt->fetchColumn();
        if ($name === false) {
            return;
        }

        $stmt = $db->prepare("SELECT id, zone_name FROM zones WHERE domain_id = :id ORDER BY id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (in_array($name, array_column($rows, 'zone_name'), true)) {
            return;
        }

        // A stale row of a deleted zone may still hold the name; the consistency check reports it.
        // This zone's own rows are left out: a case-only rename matches them on MySQL collations
        $stmt = $db->prepare("SELECT 1 FROM zones WHERE zone_name = :name AND (domain_id IS NULL OR domain_id <> :id)");
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        // A row named in another case goes first: on MySQL any other row would collide with it
        $stale = array_values(array_filter($rows, static fn(array $row): bool => $row['zone_name'] !== null));
        usort($stale, static fn(array $a, array $b): int => (strcasecmp($b['zone_name'], $name) === 0) <=> (strcasecmp($a['zone_name'], $name) === 0));
        $target = $stale[0] ?? $rows[0] ?? null;
        if ($target !== null) {
            $stmt = $db->prepare("UPDATE zones SET zone_name = :name WHERE id = :id");
            $stmt->bindValue(':id', (int)$target['id'], PDO::PARAM_INT);
        } elseif ($createRow) {
            $stmt = $db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id, zone_name) VALUES (:id, NULL, 0, :name)");
            $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        } else {
            return;
        }
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->execute();
    }
}
