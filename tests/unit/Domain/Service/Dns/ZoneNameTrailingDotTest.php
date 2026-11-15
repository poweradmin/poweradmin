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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;
use ReflectionProperty;

class ZoneNameTrailingDotTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAZoneNameWithATrailingDotIsStoredWithoutIt(): void
    {
        $_SESSION['userid'] = 1;
        $db = new PDO('sqlite::memory:');
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->expects($this->once())->method('createZone')->with('example.com', 'SLAVE', '192.0.2.1')->willReturn(false);
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

        $this->assertFalse($manager->addDomain($db, 'example.com.', null, 'SLAVE', '192.0.2.1', 'none'));
    }

    public function testAnExistingZoneIsFoundWhenTheNameCarriesATrailingDot(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec("INSERT INTO domains (name) VALUES ('example.com')");
        $config = $this->createMock(ConfigurationManager::class);
        $repository = new SqlDomainRepository($db, $config);

        $this->assertTrue($repository->domainExists('example.com'));
        $this->assertTrue($repository->domainExists('example.com.'));
        $this->assertFalse($repository->domainExists('other.com.'));
    }
}
