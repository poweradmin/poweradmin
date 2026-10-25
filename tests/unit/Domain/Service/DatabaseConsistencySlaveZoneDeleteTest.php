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
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)",
                "CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER, UNIQUE (api_key_id, zone_id))",
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
}
