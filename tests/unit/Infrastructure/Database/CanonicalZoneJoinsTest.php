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

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;

/**
 * The canonical id joins zones to the tables keyed by it: zones_groups for group ownership
 * and log_zones for the zone log. Both store the canonical value, so a row stranded at
 * domain_id = 0 only joins once the expression folds that 0 into the row's own id.
 *
 * These execute the same join shapes the repositories build, so a stranded zone is proven
 * to reach its group and its log entries.
 */
class CanonicalZoneJoinsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE log_zones (id INTEGER PRIMARY KEY, zone_id INTEGER, event TEXT)");

        // A stranded zone (createZone interrupted before its backfill), a healthy one and a
        // migrated one. zones_groups and log_zones key on the canonical id in every case.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES
            (55, 0, 'stranded.example.com', 1),
            (60, 60, 'healthy.example.com', 1),
            (7, 201, 'migrated.example.com', 1)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (55, 9), (60, 9), (201, 9)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (5, 9)");
        $this->db->exec("INSERT INTO log_zones (zone_id, event) VALUES (55, 'a'), (60, 'b'), (201, 'c')");
    }

    /**
     * @return list<string>
     */
    private function zoneNames(string $sql): array
    {
        $names = $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        sort($names);

        return $names;
    }

    public function testGroupOwnershipJoinReachesAStrandedZone(): void
    {
        // The shape ApiZoneRepository builds for group-owned zones.
        $canonical = CanonicalZoneSql::canonicalIdColumn('z');
        $sql = "SELECT z.zone_name FROM zones z WHERE EXISTS (
                    SELECT 1 FROM zones_groups zg
                    INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                    WHERE zg.domain_id = $canonical AND ugm.user_id = 5
                )";

        $this->assertSame(
            ['healthy.example.com', 'migrated.example.com', 'stranded.example.com'],
            $this->zoneNames($sql)
        );
    }

    public function testBareCoalesceLosesTheStrandedZoneFromTheGroupJoin(): void
    {
        // Why the conversion mattered: COALESCE alone leaves the stranded row at 0, which
        // matches no zones_groups entry.
        $sql = "SELECT z.zone_name FROM zones z WHERE EXISTS (
                    SELECT 1 FROM zones_groups zg
                    INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                    WHERE zg.domain_id = COALESCE(z.domain_id, z.id) AND ugm.user_id = 5
                )";

        $this->assertSame(['healthy.example.com', 'migrated.example.com'], $this->zoneNames($sql));
    }

    public function testZoneLogJoinReachesAStrandedZone(): void
    {
        // The shape DbZoneLogger builds.
        $canonical = CanonicalZoneSql::canonicalIdColumn('zones');
        $sql = "SELECT zones.zone_name FROM log_zones
                INNER JOIN zones ON $canonical = log_zones.zone_id
                WHERE zones.zone_name IS NOT NULL";

        $this->assertSame(
            ['healthy.example.com', 'migrated.example.com', 'stranded.example.com'],
            $this->zoneNames($sql)
        );
    }

    public function testCanonicalIdIsEmittedForEveryShape(): void
    {
        $rows = $this->db->query(
            "SELECT id, " . CanonicalZoneSql::canonicalIdColumn() . " AS cid FROM zones ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $actual = [];
        foreach ($rows as $row) {
            $actual[(int)$row['id']] = (int)$row['cid'];
        }

        $this->assertSame([7 => 201, 55 => 55, 60 => 60], $actual);
    }
}
