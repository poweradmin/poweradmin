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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\ZoneSyncService;
use Poweradmin\Domain\Service\DatabaseConsistencyService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Removing a zone through the consistency repair or the API sync detaches its log lines,
 * as the regular zone delete does, so a reused zone id does not inherit them.
 */
class ZoneRemovalDetachesLogsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $this->db->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, comment TEXT)");
        $this->db->exec("CREATE TABLE record_comment_links (record_id TEXT PRIMARY KEY, comment_id INTEGER UNIQUE)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_name TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)");
        $this->db->exec("CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, flags INTEGER, active INTEGER, content TEXT)");
        $this->db->exec("CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE log_zones (id INTEGER PRIMARY KEY, zone_id INTEGER, event TEXT)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (7, 'gone.example', 'SLAVE')");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 7, 1, 'gone.example')");
        $this->db->exec("INSERT INTO log_zones (id, zone_id, event) VALUES (1, 7, 'zone gone'), (2, 8, 'other zone')");
    }

    private function assertLogsDetachedForZoneSeven(): void
    {
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM log_zones WHERE zone_id = 7')->fetchColumn());
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM log_zones WHERE zone_id IS NULL')->fetchColumn());
        $this->assertSame(8, (int)$this->db->query("SELECT zone_id FROM log_zones WHERE event = 'other zone'")->fetchColumn());
    }

    public function testSlaveZoneDeleteDetachesTheLogLines(): void
    {
        $config = $this->createStub(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, mixed $default = null) => $key === 'type' ? 'sqlite' : $default);

        $this->assertTrue((new DatabaseConsistencyService($this->db, $config))->deleteSlaveZone(7));
        $this->assertLogsDetachedForZoneSeven();
    }

    public function testApiModeSlaveZoneDeleteDetachesTheLogLines(): void
    {
        $config = $this->createStub(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, mixed $default = null) => $default);
        $backend = $this->createStub(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZoneNameById')->willReturn('gone.example');
        $backend->method('deleteZone')->willReturn(true);

        $this->assertTrue((new DatabaseConsistencyService($this->db, $config, $backend))->deleteSlaveZone(7));
        $this->assertLogsDetachedForZoneSeven();
    }

    public function testOrphanedZoneRemovalDetachesTheLogLines(): void
    {
        $service = new ZoneSyncService($this->db, $this->createStub(DnsBackendProvider::class));
        $method = new \ReflectionMethod(ZoneSyncService::class, 'removeOrphanedZones');

        $this->assertSame(1, $method->invoke($service, [], ['gone.example' => ['id' => 1, 'canonical_id' => 7]]));
        $this->assertLogsDetachedForZoneSeven();
    }
}
