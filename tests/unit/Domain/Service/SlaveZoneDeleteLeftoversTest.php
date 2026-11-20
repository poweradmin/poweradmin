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
 * The consistency repair that deletes a slave zone removes what the regular zone delete does:
 * metadata, DNSSEC keys and template links, so a reused domain id starts clean.
 */
class SlaveZoneDeleteLeftoversTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)",
                "CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, comment TEXT)",
                "CREATE TABLE record_comment_links (record_id TEXT PRIMARY KEY, comment_id INTEGER UNIQUE)",
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_name TEXT)",
                "CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)",
                "CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, flags INTEGER, active INTEGER, content TEXT)",
                "CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE log_zones (id INTEGER PRIMARY KEY, zone_id INTEGER, event TEXT)",
                "INSERT INTO domains (id, name, type) VALUES (7, 'gone.example', 'SLAVE'), (8, 'stays.example', 'SLAVE')",
                "INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 7, 1, 'gone.example'), (2, 8, 1, 'stays.example')",
                "INSERT INTO domainmetadata (domain_id, kind, content) VALUES (7, 'ALLOW-AXFR-FROM', '0.0.0.0/0'), (8, 'ALSO-NOTIFY', '192.0.2.1')",
                "INSERT INTO cryptokeys (domain_id, flags, active, content) VALUES (7, 257, 1, 'key'), (8, 257, 1, 'key')",
                "INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (7, 1, 3), (8, 2, 3)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    public function testSqlModeDeleteRemovesMetadataKeysAndTemplateLinks(): void
    {
        $config = $this->createStub(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, mixed $default = null) => $key === 'type' ? 'sqlite' : $default);

        $this->assertTrue((new DatabaseConsistencyService($this->db, $config))->deleteSlaveZone(7));

        $this->assertOnlyZoneEightKeepsRows(['domainmetadata', 'cryptokeys', 'records_zone_templ']);
    }

    public function testApiModeDeleteRemovesTemplateLinks(): void
    {
        $config = $this->createStub(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, mixed $default = null) => $default);
        $backend = $this->createStub(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZoneNameById')->willReturn('gone.example');
        $backend->method('deleteZone')->willReturn(true);

        $this->assertTrue((new DatabaseConsistencyService($this->db, $config, $backend))->deleteSlaveZone(7));

        $this->assertOnlyZoneEightKeepsRows(['records_zone_templ']);
    }

    /** @param list<string> $tables */
    private function assertOnlyZoneEightKeepsRows(array $tables): void
    {
        foreach ($tables as $table) {
            $this->assertSame([8], array_map('intval', $this->db->query("SELECT domain_id FROM $table")->fetchAll(PDO::FETCH_COLUMN)), $table);
        }
    }
}
