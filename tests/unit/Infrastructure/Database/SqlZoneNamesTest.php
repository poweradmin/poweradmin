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
use Poweradmin\Infrastructure\Database\SqlZoneNames;

/**
 * Under the SQL backend each zone keeps one named zones row, so a later switch to the
 * API backend finds the zone with its owners instead of adding it again unowned.
 */
class SqlZoneNamesTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT, master TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0, zone_name TEXT, zone_type TEXT, zone_master TEXT)");
        $this->db->exec("CREATE UNIQUE INDEX idx_zones_zone_name ON zones(zone_name)");
        $this->db->exec("INSERT INTO domains (id, name, type, master) VALUES (10, 'a.example', 'SLAVE', '192.0.2.1')");
    }

    /** @return list<array{int, ?string}> owner and zone_name of each row, by id */
    private function rows(): array
    {
        return array_map(
            static fn(array $r): array => [(int)$r['owner'], $r['zone_name']],
            $this->db->query("SELECT owner, zone_name FROM zones ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function testTheLowestRowGetsTheNameAndTheSyncTheTypeAndMaster(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (10, 1), (10, 2)");

        SqlZoneNames::ensureNamed($this->db, 'domains', 10);

        $this->assertSame([[1, 'a.example'], [2, null]], $this->rows());
        $this->assertSame([null, null], $this->db->query("SELECT zone_type, zone_master FROM zones WHERE id = 1")->fetch(PDO::FETCH_NUM));
    }

    public function testAStaleNameIsCorrected(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_name) VALUES (10, 1, NULL), (10, 2, 'old.example')");

        SqlZoneNames::ensureNamed($this->db, 'domains', 10);

        $this->assertSame([[1, null], [2, 'a.example']], $this->rows());
    }

    public function testAZoneWithoutRowsGetsAnOwnerlessOneOnlyWhenAsked(): void
    {
        SqlZoneNames::ensureNamed($this->db, 'domains', 10);
        $this->assertSame([], $this->rows());

        SqlZoneNames::ensureNamed($this->db, 'domains', 10, true);
        $this->assertSame([[0, 'a.example']], $this->rows());
        $this->assertNull($this->db->query("SELECT owner FROM zones")->fetchColumn());
    }

    public function testANamedZoneIsLeftAlone(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_name) VALUES (10, 1, NULL), (10, 2, 'a.example')");

        SqlZoneNames::ensureNamed($this->db, 'domains', 10);

        $this->assertSame([[1, null], [2, 'a.example']], $this->rows());
    }

    public function testRemovingTheNamedOwnerMovesTheNameToAnotherOwner(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_name) VALUES (10, 1, 'a.example'), (10, 2, NULL)");
        $this->db->exec("DELETE FROM zones WHERE owner = 1");

        SqlZoneNames::ensureNamed($this->db, 'domains', 10);

        $this->assertSame([[2, 'a.example']], $this->rows());
    }

    public function testANameAStaleRowHoldsIsNotTakenOver(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_name) VALUES (99, 1, 'a.example'), (10, 2, NULL)");

        SqlZoneNames::ensureNamed($this->db, 'domains', 10);

        $this->assertSame([[1, 'a.example'], [2, null]], $this->rows());
    }

    public function testAZoneMissingFromDomainsIsIgnored(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (77, 1)");
        SqlZoneNames::ensureNamed($this->db, 'domains', 77, true);

        $this->assertSame([[1, null]], $this->rows());
    }
}
