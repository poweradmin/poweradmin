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
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SupermasterManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;

/**
 * Confirms the factory wires each DNS service to its interface for the SQL backend.
 */
class DnsServiceFactoryTest extends TestCase
{
    private function makeConfig(): ConfigurationManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function (string $section, string $key, $default = null) {
            if ($section === 'dns' && $key === 'backend') {
                return 'sql';
            }
            if ($section === 'database' && $key === 'type') {
                return 'mysql';
            }
            return $default;
        });
        return $config;
    }

    public function testCreatesSOARecordManager(): void
    {
        $db = $this->createMock(PDO::class);
        $this->assertInstanceOf(
            SOARecordManagerInterface::class,
            DnsServiceFactory::createSOARecordManager($db, $this->makeConfig())
        );
    }

    public function testCreatesDomainRepository(): void
    {
        $db = $this->createMock(PDO::class);
        $this->assertInstanceOf(
            DomainRepositoryInterface::class,
            DnsServiceFactory::createDomainRepository($db, $this->makeConfig())
        );
    }

    public function testCreatesRecordRepository(): void
    {
        $db = $this->createMock(PDO::class);
        $this->assertInstanceOf(
            RecordRepositoryInterface::class,
            DnsServiceFactory::createRecordRepository($db, $this->makeConfig())
        );
    }

    public function testCreatesRecordManager(): void
    {
        $db = $this->createMock(PDO::class);
        $this->assertInstanceOf(
            RecordManagerInterface::class,
            DnsServiceFactory::createRecordManager($db, $this->makeConfig())
        );
    }

    public function testCreatesDomainManager(): void
    {
        $db = $this->createMock(PDO::class);
        $this->assertInstanceOf(
            DomainManagerInterface::class,
            DnsServiceFactory::createDomainManager($db, $this->makeConfig())
        );
    }

    public function testCreatesSupermasterManager(): void
    {
        $db = $this->createMock(PDO::class);
        $this->assertInstanceOf(
            SupermasterManagerInterface::class,
            DnsServiceFactory::createSupermasterManager($db, $this->makeConfig())
        );
    }
}
