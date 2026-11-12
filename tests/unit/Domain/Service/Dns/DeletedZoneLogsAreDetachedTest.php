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

namespace Unit\Domain\Service\Dns;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * SQLite reuses the id of a deleted zone, so log rows left on the old id would show up
 * in the zone log of whoever owns the next zone created with that id.
 */
class DeletedZoneLogsAreDetachedTest extends TestCase
{
    private const ZONE_ID = 5;

    private PDOCommon $db;
    private ConfigurationManager $config;

    protected function setUp(): void
    {
        $this->db = new PDOCommon('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, perm_templ INTEGER)',
                'CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)',
                'CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)',
                'CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)',
                'CREATE TABLE user_groups (id INTEGER PRIMARY KEY, perm_templ INTEGER)',
                'CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)',
                'CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)',
                'CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)',
                'CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)',
                'CREATE TABLE log_zones (id INTEGER PRIMARY KEY, event TEXT NOT NULL, created_at TEXT, priority INTEGER, zone_id INTEGER)',
                'CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT)',
                'CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER)',
                'CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER)',
                'CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER)',
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'user_is_ueberuser')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'Admin')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (1, 'admin', 1)");
        $this->db->exec("INSERT INTO domains (id, name) VALUES (" . self::ZONE_ID . ", 'example.com')");
        $this->db->exec("INSERT INTO log_zones (event, priority, zone_id) VALUES ('operation:edit_record', 6, " . self::ZONE_ID . "), ('other zone', 6, 6)");
        $_SESSION['userid'] = 1;

        $this->config = $this->createMock(ConfigurationManager::class);
        $this->config->method('get')->willReturnCallback(static fn(string $group, string $key, mixed $default = null): mixed => $group === 'database' && $key === 'type' ? 'sqlite' : $default);
    }

    #[RunInSeparateProcess]
    public function testWebDeleteDetachesTheLogRows(): void
    {
        $manager = new DomainManager(
            $this->db,
            $this->config,
            $this->createMock(SOARecordManagerInterface::class),
            $this->createMock(DomainRepositoryInterface::class)
        );

        $this->assertTrue($manager->deleteDomain(self::ZONE_ID));
        $this->assertLogsDetached();
    }

    public function testRepositoryDeleteDetachesTheLogRows(): void
    {
        $this->assertTrue((new DbZoneRepository($this->db, $this->config))->deleteZone(self::ZONE_ID));
        $this->assertLogsDetached();
    }

    public function testTheLoggerCanDetachAZoneAfterItsDeleteEntryIsWritten(): void
    {
        $this->db->exec("INSERT INTO log_zones (event, priority, zone_id) VALUES ('operation:delete_zone', 6, " . self::ZONE_ID . ")");

        (new DbZoneLogger($this->db))->detachZone(self::ZONE_ID);

        $this->assertSame('0', (string)$this->db->query('SELECT count(*) FROM log_zones WHERE zone_id = ' . self::ZONE_ID)->fetchColumn());
    }

    private function assertLogsDetached(): void
    {
        $this->assertSame('0', (string)$this->db->query('SELECT count(*) FROM log_zones WHERE zone_id = ' . self::ZONE_ID)->fetchColumn());
        $this->assertSame('1', (string)$this->db->query("SELECT count(*) FROM log_zones WHERE zone_id IS NULL AND event = 'operation:edit_record'")->fetchColumn());
        $this->assertSame('1', (string)$this->db->query('SELECT count(*) FROM log_zones WHERE zone_id = 6')->fetchColumn());
    }
}
