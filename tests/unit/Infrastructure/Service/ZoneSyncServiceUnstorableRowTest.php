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
use Poweradmin\Infrastructure\Service\ZoneSyncService;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * One zone row the database refuses must not stop the rest of the sync or keep the throttle open.
 */
class ZoneSyncServiceUnstorableRowTest extends TestCase
{
    private PDO $db;
    private ArraySession $session;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER, comment TEXT, zone_name TEXT COLLATE NOCASE UNIQUE, zone_type TEXT, zone_master TEXT)');
        $this->db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)');
        $this->db->exec('CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER)');
        $this->db->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT \'string\')');
        $this->db->exec("CREATE TRIGGER refuse_master BEFORE UPDATE ON zones WHEN NEW.zone_master = 'refused' BEGIN SELECT RAISE(ABORT, 'refused master'); END");
        $this->session = new ArraySession();
    }

    /** @param list<array<string, mixed>> $zones */
    private function service(array $zones): ZoneSyncService
    {
        $backend = $this->createMock(ZoneReadBackendInterface::class);
        $backend->method('getZones')->willReturn(array_map(
            static fn(array $zone): array => $zone + ['id' => 0, 'type' => 'MASTER', 'master' => '', 'account' => ''],
            $zones
        ));

        return new ZoneSyncService($this->db, $backend, $this->session);
    }

    /** @return list<string> */
    private function names(): array
    {
        return $this->db->query('SELECT zone_name FROM zones ORDER BY zone_name')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testAZoneWhoseMastersDoNotFitTheColumnIsSkippedAndTheRestIsImported(): void
    {
        $result = $this->service([
            ['name' => 'long.example', 'master' => str_repeat('2001:db8::1,', 30)],
            ['name' => 'fine.example'],
        ])->sync();

        $this->assertSame(1, $result['added']);
        $this->assertSame(['fine.example'], $this->names());
        $this->assertNotSame(0, $this->session->get('zone_sync_last', 0));
    }

    public function testANameThatCollidesWithALocalRowUnderTheCollationIsSkipped(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 1, 4, 'Cafe.example')");

        $result = $this->service([
            ['name' => 'Cafe.example'],
            ['name' => 'cafe.example'],
            ['name' => 'other.example'],
        ])->sync();

        $this->assertSame(1, $result['added']);
        $this->assertSame(['Cafe.example', 'other.example'], $this->names());
        $this->assertNotSame(0, $this->session->get('zone_sync_last', 0));
    }

    public function testAnUpdateTheDatabaseRefusesDoesNotStopTheOtherUpdates(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name, zone_type, zone_master) VALUES (1, 1, 4, 'a.example', 'MASTER', ''), (2, 2, 4, 'b.example', 'MASTER', '')");

        $result = $this->service([
            ['name' => 'a.example', 'master' => 'refused'],
            ['name' => 'b.example', 'master' => '192.0.2.1'],
        ])->sync();

        $this->assertSame(1, $result['updated']);
        $this->assertSame('192.0.2.1', $this->db->query("SELECT zone_master FROM zones WHERE zone_name = 'b.example'")->fetchColumn());
        $this->assertNotSame(0, $this->session->get('zone_sync_last', 0));
    }

    public function testARefusedRowInsideTheCallersTransactionDoesNotRollBackTheOthers(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 1, 4, 'Cafe.example')");
        $this->db->beginTransaction();

        $result = $this->service([
            ['name' => 'Cafe.example'],
            ['name' => 'cafe.example'],
            ['name' => 'other.example'],
        ])->sync();
        $this->db->commit();

        $this->assertSame(1, $result['added']);
        $this->assertSame(['Cafe.example', 'other.example'], $this->names());
    }
}
