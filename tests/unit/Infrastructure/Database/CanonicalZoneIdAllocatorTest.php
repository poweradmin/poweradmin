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
use Poweradmin\Infrastructure\Database\CanonicalZoneIdAllocator;

/**
 * A zone created in API backend mode must not take a canonical id another zone, a group
 * assignment or an API key scope already uses, or it would take over that zone's id.
 */
class CanonicalZoneIdAllocatorTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT, zone_templ_id INTEGER NOT NULL DEFAULT 0, zone_name TEXT, zone_type TEXT, zone_master TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER)");
        $this->db->exec("CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT 'string')");
    }

    /** @return array<int, int> id => domain_id of the named rows */
    private function zones(): array
    {
        $rows = [];
        foreach ($this->db->query("SELECT id, domain_id FROM zones WHERE zone_name IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['id']] = (int)$row['domain_id'];
        }

        return $rows;
    }

    public function testANewZoneKeepsItsRowIdWhenNothingElseUsesIt(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (1, 1, 'a.example')");
        $allocator = new CanonicalZoneIdAllocator($this->db);
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (50, NULL, 'new.example')");

        $this->assertSame(50, $allocator->settle(50));
        $this->assertSame([1 => 1, 50 => 50], $this->zones());
    }

    public function testATakenIdMovesTheNewRowAboveEveryUsedId(): void
    {
        // 99 is a migrated zone's canonical id, 120 a key scope
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (98, 99, 'migrated.example')");
        $this->db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (1, 120)");
        $allocator = new CanonicalZoneIdAllocator($this->db);
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner, comment) VALUES (99, NULL, 'new.example', 4, 'kept')");

        $this->assertSame(121, $allocator->settle(99));
        $this->assertSame([98 => 99, 121 => 121], $this->zones());
        $this->assertSame(['owner' => 4, 'comment' => 'kept'], array_map(fn($v) => is_numeric($v) ? (int)$v : $v, $this->db->query("SELECT owner, comment FROM zones WHERE id = 121")->fetch(PDO::FETCH_ASSOC)));
    }

    public function testAZoneCreatedAfterAMovedOneKeepsItsOwnIdAgain(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (1, 2, 'migrated.example')");
        $allocator = new CanonicalZoneIdAllocator($this->db);
        $this->db->exec("INSERT INTO zones (domain_id, zone_name) VALUES (NULL, 'first.example')");
        $moved = $allocator->settle((int)$this->db->lastInsertId());
        $this->db->exec("INSERT INTO zones (domain_id, zone_name) VALUES (NULL, 'second.example')");
        $next = (int)$this->db->lastInsertId();

        $this->assertSame(3, $moved);
        $this->assertSame($next, $allocator->settle($next), 'No id + 1 chain after a move');
    }

    public function testAStaleGroupAssignmentCountsAsUsed(): void
    {
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (130, 7)");
        $allocator = new CanonicalZoneIdAllocator($this->db);
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (130, NULL, 'other.example')");

        $this->assertSame(131, $allocator->settle(130));
    }
}
