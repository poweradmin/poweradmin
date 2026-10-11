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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use TestHelpers\FakeConfiguration;

/**
 * Under the SQL backend a zone reached only through a group or an API key scope has no
 * owner row. The grant now names the zone right away, so a later switch to the API
 * backend finds it under its id and the grant keeps applying (gh #1649).
 */
#[CoversClass(DbZoneGroupRepository::class)]
#[CoversClass(DbApiKeyRepository::class)]
class GrantZoneNamingTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT, master TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0, zone_name TEXT, zone_type TEXT, zone_master TEXT)");
        $this->db->exec("CREATE UNIQUE INDEX idx_zones_zone_name ON zones(zone_name)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER, created_at TEXT DEFAULT '')");
        $this->db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (10, 'groups-only.example', 'MASTER'), (20, 'owned.example', 'MASTER'), (30, 'keyed.example', 'MASTER')");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (20, 4)");
    }

    /** @return list<array{int, ?int, ?string}> domain_id, owner and zone_name of each row, by id */
    private function rows(): array
    {
        return array_map(
            static fn(array $r): array => [(int)$r['domain_id'], $r['owner'] === null ? null : (int)$r['owner'], $r['zone_name']],
            $this->db->query("SELECT domain_id, owner, zone_name FROM zones ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function config(): FakeConfiguration
    {
        return new FakeConfiguration(['database' => ['type' => 'sqlite']]);
    }

    public function testAGroupGrantNamesAZoneWithoutOwnerRowsInAnOwnerlessRow(): void
    {
        (new DbZoneGroupRepository($this->db, $this->config(), false))->add(10, 7);

        $this->assertSame([[20, 4, null], [10, null, 'groups-only.example']], $this->rows());
    }

    public function testAGroupGrantNamesTheExistingOwnerRowInsteadOfAddingOne(): void
    {
        (new DbZoneGroupRepository($this->db, $this->config(), false))->add(20, 7);

        $this->assertSame([[20, 4, 'owned.example']], $this->rows());
    }

    public function testAGroupGrantIsStillStoredAndReturned(): void
    {
        $link = (new DbZoneGroupRepository($this->db, $this->config(), false))->add(10, 7);

        $this->assertSame([10, 7], [$link->getDomainId(), $link->getGroupId()]);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM zones_groups")->fetchColumn());
    }

    public function testTheApiBackendNamesNothingOnAGroupGrant(): void
    {
        (new DbZoneGroupRepository($this->db, $this->config(), true))->add(10, 7);

        $this->assertSame([[20, 4, null]], $this->rows());
    }

    public function testAnApiKeyScopeNamesEachScopedZone(): void
    {
        (new DbApiKeyRepository($this->db, $this->config()))->saveZoneIds(1, [30, 20, 30]);

        $this->assertSame([[20, 4, 'owned.example'], [30, null, 'keyed.example']], $this->rows());
        $this->assertSame([20, 30], array_map('intval', $this->db->query("SELECT zone_id FROM api_key_zones ORDER BY zone_id")->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testAScopedIdWithoutAZoneAddsNoRow(): void
    {
        (new DbApiKeyRepository($this->db, $this->config()))->saveZoneIds(1, [999]);

        $this->assertSame([[20, 4, null]], $this->rows());
    }

    public function testTheApiBackendNamesNothingOnAnApiKeyScope(): void
    {
        (new DbApiKeyRepository($this->db, $this->config(), null, true, true))->saveZoneIds(1, [30]);

        $this->assertSame([[20, 4, null]], $this->rows());
    }

    public function testASecondGroupGrantOnTheSameZoneAddsNoSecondRow(): void
    {
        $groups = new DbZoneGroupRepository($this->db, $this->config(), false);
        $groups->add(10, 7);
        $groups->add(10, 8);

        $this->assertSame([[20, 4, null], [10, null, 'groups-only.example']], $this->rows());
    }

    public function testAGroupGrantIsNotStoredWhenTheZoneCannotBeNamed(): void
    {
        $this->db->exec("DROP TABLE domains");

        try {
            (new DbZoneGroupRepository($this->db, $this->config(), false))->add(10, 7);
            $this->fail('The naming failure should surface.');
        } catch (\PDOException) {
        }

        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM zones_groups")->fetchColumn());
    }

    public function testAnApiKeyKeepsItsScopeWhenTheZoneCannotBeNamed(): void
    {
        $this->db->exec("DROP TABLE domains");

        (new DbApiKeyRepository($this->db, $this->config()))->saveZoneIds(1, [30]);

        $this->assertSame([30], array_map('intval', $this->db->query("SELECT zone_id FROM api_key_zones")->fetchAll(PDO::FETCH_COLUMN)));
    }
}
