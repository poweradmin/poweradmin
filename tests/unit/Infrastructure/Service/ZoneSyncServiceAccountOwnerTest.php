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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\ZoneReadBackendInterface;
use Poweradmin\Infrastructure\Repository\AccountOwnerLookup;
use Poweradmin\Infrastructure\Service\ZoneSyncService;
use Poweradmin\Infrastructure\Session\ArraySession;

class ZoneSyncServiceAccountOwnerTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER, comment TEXT, zone_name TEXT, zone_type TEXT, zone_master TEXT)');
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $this->db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)');
        $this->db->exec('CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER)');
        $this->db->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT \'string\')');
        $this->db->exec("INSERT INTO users (id, username) VALUES (4, 'alice')");
    }

    public function testANewZoneIsGivenToTheUserItsAccountNamesOnlyWhenAdoptionIsOn(): void
    {
        $this->assertSame(['matched.example' => 0, 'unknown.example' => 0, 'plain.example' => 0], $this->ownersAfterSync(null));

        $this->db->exec('DELETE FROM zones');
        $this->assertSame(['matched.example' => 4, 'unknown.example' => 0, 'plain.example' => 0], $this->ownersAfterSync(new AccountOwnerLookup($this->db)));
    }

    public function testAZoneGoneFromPowerDnsTakesItsGrantsByCanonicalIdAndLeavesOthersAlone(): void
    {
        // Migrated row 7 (canonical 4011) with an extra owner is gone from PowerDNS; row 20 owns canonical 7
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES
            (7, 4011, 4, 'gone.example'), (8, 4011, 5, NULL), (20, 7, 4, 'kept.example')");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (4011, 1), (7, 2)");
        $backend = $this->createMock(ZoneReadBackendInterface::class);
        $backend->method('getZones')->willReturn([['id' => 7, 'name' => 'kept.example', 'type' => 'MASTER', 'master' => '', 'account' => '']]);

        (new ZoneSyncService($this->db, $backend, new ArraySession()))->sync();

        $this->assertSame([20], array_map('intval', $this->db->query('SELECT id FROM zones ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([7], array_map('intval', $this->db->query('SELECT domain_id FROM zones_groups')->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @return array<string, int>
     */
    private function ownersAfterSync(?AccountOwnerLookup $lookup): array
    {
        $backend = $this->createMock(ZoneReadBackendInterface::class);
        $backend->method('getZones')->willReturn([
            ['id' => 0, 'name' => 'matched.example', 'type' => 'SLAVE', 'master' => '192.0.2.1', 'account' => 'alice'],
            ['id' => 0, 'name' => 'unknown.example', 'type' => 'SLAVE', 'master' => '192.0.2.1', 'account' => 'bob'],
            ['id' => 0, 'name' => 'plain.example', 'type' => 'MASTER', 'master' => '', 'account' => ''],
        ]);

        (new ZoneSyncService($this->db, $backend, new ArraySession(), accountOwners: $lookup))->sync();

        $owners = [];
        foreach ($this->db->query('SELECT zone_name, owner FROM zones ORDER BY id') as $row) {
            $owners[$row['zone_name']] = (int)$row['owner'];
        }

        return $owners;
    }
}
