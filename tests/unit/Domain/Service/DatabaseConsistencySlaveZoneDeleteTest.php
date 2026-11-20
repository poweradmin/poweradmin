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
use Poweradmin\Domain\Service\DatabaseConsistencyService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Deleting a slave zone without masters in SQL mode leaves no API key scoped to
 * the id a later zone may be given.
 */
class DatabaseConsistencySlaveZoneDeleteTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT)",
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER)",
                "CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, modified_at INTEGER, account TEXT, comment TEXT)",
                "CREATE TABLE record_comment_links (record_id TEXT PRIMARY KEY, comment_id INTEGER UNIQUE)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)",
                "CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER, UNIQUE (api_key_id, zone_id))",
                "CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)",
                "CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, flags INTEGER, active INTEGER, content TEXT)",
                "CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id TEXT, zone_templ_id INTEGER)",
                "INSERT INTO domainmetadata (domain_id, kind, content) VALUES (3, 'SOA-EDIT', 'x'), (4, 'SOA-EDIT', 'y')",
                "INSERT INTO cryptokeys (domain_id, flags, active, content) VALUES (3, 257, 1, 'k'), (4, 257, 1, 'k')",
                "INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (3, 1, 1), (4, 2, 1)",
                "INSERT INTO records_zone_templ_api (domain_id, record_id, zone_templ_id) VALUES (3, 'a', 1), (4, 'b', 1)",
                "CREATE TABLE log_zones (id INTEGER PRIMARY KEY, zone_id INTEGER, event TEXT)",
                "INSERT INTO log_zones (zone_id, event) VALUES (3, 'slave'), (4, 'other')",
                "INSERT INTO domains VALUES (3, 'slave.example', 'SLAVE', ''), (4, 'other.example', 'MASTER', NULL)",
                "INSERT INTO zones (domain_id, owner) VALUES (3, 1), (4, 1)",
                "INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (1, 3), (2, 3), (2, 4)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    public function testDeletingTheSlaveZoneKeepsKeysScopedToItRestrictedToNoZone(): void
    {
        $provider = $this->createMock(DnsBackendProvider::class);
        $provider->method('isApiBackend')->willReturn(false);
        $config = ConfigurationManager::getInstance();
        $config->initialize();

        $this->assertTrue((new DatabaseConsistencyService($this->db, $config, $provider))->deleteSlaveZone(3));

        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM domains WHERE id = 3")->fetchColumn());
        $this->assertSame(
            [[1, 0], [2, 0], [2, 4]],
            array_map(
                static fn(array $r): array => [(int)$r[0], (int)$r[1]],
                $this->db->query("SELECT api_key_id, zone_id FROM api_key_zones ORDER BY api_key_id, zone_id")->fetchAll(PDO::FETCH_NUM)
            )
        );
    }

    public function testDeletingTheSlaveZoneRemovesItsMetadataKeysAndTemplateRows(): void
    {
        $provider = $this->createMock(DnsBackendProvider::class);
        $provider->method('isApiBackend')->willReturn(false);
        $config = ConfigurationManager::getInstance();
        $config->initialize();

        $this->assertTrue((new DatabaseConsistencyService($this->db, $config, $provider))->deleteSlaveZone(3));

        foreach (['domainmetadata', 'cryptokeys', 'records_zone_templ', 'records_zone_templ_api'] as $table) {
            $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM $table WHERE domain_id = 3")->fetchColumn(), $table);
            $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM $table WHERE domain_id = 4")->fetchColumn(), "$table of the other zone");
        }
    }

    public function testDeletingTheSlaveZoneCutsItsHistoryLooseFromTheId(): void
    {
        $provider = $this->createMock(DnsBackendProvider::class);
        $provider->method('isApiBackend')->willReturn(false);
        $config = ConfigurationManager::getInstance();
        $config->initialize();

        $this->assertTrue((new DatabaseConsistencyService($this->db, $config, $provider))->deleteSlaveZone(3));

        $this->assertNull($this->db->query("SELECT zone_id FROM log_zones WHERE event = 'slave'")->fetchColumn() ?: null);
        $this->assertSame(4, (int)$this->db->query("SELECT zone_id FROM log_zones WHERE event = 'other'")->fetchColumn());
    }
}
