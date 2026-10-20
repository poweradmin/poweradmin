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
 * The automatic sync must not wipe ownership because pdns_api.url points at an empty or
 * different PowerDNS; only the admin's manual sync may remove most zones at once.
 */
class ZoneSyncServiceRemovalGuardTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER, comment TEXT, zone_name TEXT, zone_type TEXT, zone_master TEXT)');
        $this->db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)');
        $this->db->exec('CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER)');
        $this->db->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT \'string\')');
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 1, 4, 'a.example'), (2, 2, 4, 'b.example'), (3, 3, 4, 'c.example')");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (1, 9)");
    }

    /** @param list<string> $names */
    private function service(array $names): ZoneSyncService
    {
        $backend = $this->createMock(ZoneReadBackendInterface::class);
        $backend->method('getZones')->willReturn(array_map(
            static fn(string $name): array => ['id' => 0, 'name' => $name, 'type' => 'MASTER', 'master' => '', 'account' => ''],
            $names
        ));

        return new ZoneSyncService($this->db, $backend, new ArraySession());
    }

    private function zoneCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM zones')->fetchColumn();
    }

    public function testTheAutomaticSyncKeepsEveryZoneWhenPowerDnsListsNone(): void
    {
        $result = $this->service([])->syncIfStale();

        $this->assertSame(0, $result['removed']);
        $this->assertSame(3, $this->zoneCount());
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM zones_groups')->fetchColumn());
    }

    public function testTheAutomaticSyncKeepsZonesWhenMostAreMissing(): void
    {
        $result = $this->service(['a.example'])->syncIfStale();

        $this->assertSame(0, $result['removed']);
        $this->assertSame(3, $this->zoneCount());
    }

    public function testTheAutomaticSyncImportsNothingFromADifferentServer(): void
    {
        // Importing these first would make the old zones a minority, and the next run would drop them
        $result = $this->service(['w.example', 'x.example', 'y.example', 'z.example'])->syncIfStale();

        $this->assertSame(['added' => 0, 'removed' => 0, 'updated' => 0], $result);
        $this->assertSame(3, $this->zoneCount());
    }

    public function testTheAutomaticSyncStillRemovesAFewDeletedZones(): void
    {
        $result = $this->service(['a.example', 'b.example'])->syncIfStale();

        $this->assertSame(1, $result['removed']);
        $this->assertSame(2, $this->zoneCount());
    }

    public function testTheManualSyncMayRemoveEveryZone(): void
    {
        $result = $this->service([])->sync();

        $this->assertSame(3, $result['removed']);
        $this->assertSame(0, $this->zoneCount());
    }
}
