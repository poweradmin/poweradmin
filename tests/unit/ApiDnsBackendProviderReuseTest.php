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

namespace Poweradmin\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;

class ApiDnsBackendProviderReuseTest extends TestCase
{
    public function testReusedZoneRowDropsGrantsOfThePreviousZone(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec(
            'CREATE TABLE zones (id integer PRIMARY KEY, domain_id integer NULL, owner integer NULL, zone_templ_id integer NOT NULL, '
            . 'zone_name VARCHAR(255) DEFAULT NULL, zone_type VARCHAR(8) DEFAULT NULL, zone_master VARCHAR(255) DEFAULT NULL)'
        );
        $db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)');
        $db->exec('CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)');
        $db->exec('INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (5, 100, 1), (9, 101, 1)');
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id, zone_name, zone_type) VALUES (5, 5, 2, 0, 'example.com', 'NATIVE')");
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id, zone_name) VALUES (6, 5, 3, 0, NULL)");
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id, zone_name) VALUES (7, 9, 3, 0, NULL)");
        $db->exec('INSERT INTO zones_groups (domain_id, group_id) VALUES (5, 1), (9, 1)');

        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('createZoneWithData')->willReturn([]);
        $provider = new ApiDnsBackendProvider($client, $db, new FakeConfiguration([]));

        $this->assertSame(5, $provider->createZone('example.com', 'NATIVE'));

        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM zones_groups WHERE domain_id = 5')->fetchColumn());
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM records_zone_templ WHERE domain_id = 5')->fetchColumn());
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM records_zone_templ WHERE domain_id = 9')->fetchColumn());
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM zones WHERE id = 6')->fetchColumn());
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM zones_groups WHERE domain_id = 9')->fetchColumn());
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM zones WHERE id = 7')->fetchColumn());
    }
}
