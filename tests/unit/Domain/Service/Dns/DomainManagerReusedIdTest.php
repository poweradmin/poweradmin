<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;

class DomainManagerReusedIdTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testRowsLeftByAnEarlierZoneWithTheSameIdDoNotCarryOver(): void
    {
        $_SESSION['userid'] = 1;
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, owner INTEGER NULL, comment TEXT, zone_templ_id INTEGER)');
        $db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, group_id INTEGER, created_at TEXT)');
        $db->exec('CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)');
        $db->exec('CREATE TABLE api_key_zones (api_key_id INTEGER, zone_id INTEGER)');
        $db->exec('INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (5, 7, 0), (5, NULL, 0), (6, 8, 0)');
        $db->exec('INSERT INTO zones_groups (domain_id, group_id, created_at) VALUES (5, 3, CURRENT_TIMESTAMP), (6, 3, CURRENT_TIMESTAMP)');
        $db->exec('INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (5, 11, 1), (6, 12, 1)');

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('createZone')->willReturn(5);
        $backend->method('isApiBackend')->willReturn(false);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);

        $manager = new DomainManager(
            $db,
            $this->createMock(ConfigurationManager::class),
            $this->createMock(SOARecordManagerInterface::class),
            $this->createMock(DomainRepositoryInterface::class),
            $backend
        );
        (new ReflectionProperty(DomainManager::class, 'permissionService'))->setValue($manager, $permissions);

        $this->assertTrue($manager->addDomain($db, 'example.com', null, 'SLAVE', '192.0.2.1', 'none'));

        $rows = $db->query('SELECT owner FROM zones WHERE domain_id = 5')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([null], $rows);
        $this->assertSame('0', (string)$db->query('SELECT COUNT(*) FROM zones_groups WHERE domain_id = 5')->fetchColumn());
        $this->assertSame('0', (string)$db->query('SELECT COUNT(*) FROM records_zone_templ WHERE domain_id = 5')->fetchColumn());
        $this->assertSame('1', (string)$db->query('SELECT COUNT(*) FROM zones WHERE domain_id = 6')->fetchColumn());
        $this->assertSame('1', (string)$db->query('SELECT COUNT(*) FROM zones_groups WHERE domain_id = 6')->fetchColumn());
    }
}
