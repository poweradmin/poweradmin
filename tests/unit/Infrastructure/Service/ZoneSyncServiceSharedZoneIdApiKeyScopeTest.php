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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\ZoneReadBackendInterface;
use Poweradmin\Infrastructure\Service\ZoneSyncService;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\NullLogger;

/**
 * a.example was created here with id 5 and migrated b.example carries domain_id 5, so both
 * have canonical id 5, which opens a.example. API key scopes store canonical ids.
 */
#[CoversClass(ZoneSyncService::class)]
class ZoneSyncServiceSharedZoneIdApiKeyScopeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER,
            comment TEXT, zone_name TEXT, zone_type TEXT, zone_master TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY AUTOINCREMENT, api_key_id INTEGER NOT NULL,
            zone_id INTEGER NOT NULL, UNIQUE (api_key_id, zone_id))");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name, zone_type) VALUES
            (5, 5, 1, 'a.example', 'NATIVE'), (12, 5, 2, 'b.example', 'NATIVE'),
            (30, 30, 1, 'c.example', 'NATIVE'), (40, 40, 1, 'd.example', 'NATIVE')");
        // Key 2 already holds an unmapped 0; key 3 is unrelated
        $this->db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (1, 5), (2, 5), (2, 0), (2, 30), (3, 30), (4, 40)");
    }

    /** @param string[] $apiNames */
    private function syncWith(array $apiNames): void
    {
        $backend = $this->createMock(ZoneReadBackendInterface::class);
        $backend->method('getZones')->willReturn(array_map(
            static fn(string $name): array => ['name' => $name, 'type' => 'NATIVE', 'master' => null],
            $apiNames
        ));

        (new ZoneSyncService($this->db, $backend, new ArraySession(), 300, new NullLogger()))->sync();
    }

    /** @return array<int, int[]> */
    private function keyScopes(): array
    {
        $scopes = [];
        foreach ($this->db->query("SELECT api_key_id, zone_id FROM api_key_zones ORDER BY api_key_id, zone_id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scopes[(int)$row['api_key_id']][] = (int)$row['zone_id'];
        }

        return $scopes;
    }

    public function testRemovingTheZoneASharedIdOpensLeavesItsApiKeysMatchingNoZone(): void
    {
        $this->syncWith(['b.example', 'c.example', 'd.example']);

        // Id 5 now opens b.example, which no key was restricted to
        $this->assertSame([12, 30, 40], array_map('intval', $this->db->query("SELECT id FROM zones ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([1 => [0], 2 => [0, 30], 3 => [30], 4 => [40]], $this->keyScopes());
    }

    public function testRemovingTheZoneASharedIdDoesNotOpenKeepsItsApiKeyScopes(): void
    {
        $this->syncWith(['a.example', 'c.example', 'd.example']);

        $this->assertSame([5, 30, 40], array_map('intval', $this->db->query("SELECT id FROM zones ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([1 => [5], 2 => [0, 5, 30], 3 => [30], 4 => [40]], $this->keyScopes());
    }

    public function testRemovingTheZoneAnIdOpensBeforeAnotherZonesRowIdLeavesItsApiKeysMatchingNoZone(): void
    {
        // Id 60 opens b60.example by domain_id; without it, id 60 opens c61.example by row id
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name, zone_type) VALUES
            (60, 61, 1, 'c61.example', 'NATIVE'), (70, 60, 1, 'b60.example', 'NATIVE')");
        $this->db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (5, 60)");

        $this->syncWith(['a.example', 'b.example', 'c.example', 'd.example', 'c61.example']);

        $this->assertSame([1 => [5], 2 => [0, 5, 30], 3 => [30], 4 => [40], 5 => [0]], $this->keyScopes());
    }

    public function testRemovingAZoneOnAnUnsharedIdKeepsItsApiKeyScopes(): void
    {
        $this->syncWith(['a.example', 'b.example', 'c.example']);

        $this->assertSame([5, 12, 30], array_map('intval', $this->db->query("SELECT id FROM zones ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([1 => [5], 2 => [0, 5, 30], 3 => [30], 4 => [40]], $this->keyScopes());
    }
}
