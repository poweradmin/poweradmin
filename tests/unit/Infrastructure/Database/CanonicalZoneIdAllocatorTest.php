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
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER)");
    }

    public function testANewZoneKeepsItsRowIdWhenNothingElseUsesIt(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (1, 1, 'a.example'), (50, NULL, 'new.example')");

        $this->assertSame(50, (new CanonicalZoneIdAllocator($this->db))->allocate(50));
    }

    public function testANewZoneNeverTakesAnIdAnotherZoneOrGrantUses(): void
    {
        // 99 is a migrated zone's canonical id, 120 a key scope, 130 a stale group assignment
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (98, 99, 'migrated.example'), (99, NULL, 'new.example'), (130, NULL, 'other.example')");
        $this->db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (1, 120)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (130, 7)");

        $allocator = new CanonicalZoneIdAllocator($this->db);

        $this->assertSame(131, $allocator->allocate(99), 'Above every zone id, group assignment and scope');
        $this->assertSame(132, $allocator->allocate(130));
    }

    public function testIdsAllocatedInOneTransactionNeverRepeat(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (1, 5, 'migrated.example')");
        $allocator = new CanonicalZoneIdAllocator($this->db);

        $first = $allocator->allocate(5);
        $second = $allocator->allocate($first);

        $this->assertSame(6, $first);
        $this->assertSame(7, $second);
    }
}
