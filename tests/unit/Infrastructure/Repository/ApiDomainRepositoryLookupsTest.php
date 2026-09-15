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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiDomainRepository;

/**
 * Pins the four id lookups on the API-mode domain repository. Existence is
 * answered from the local zones row through getZoneNameById, so a stale row
 * never costs an HTTP round trip and never reports a locally known zone as
 * missing; the master lookup is passed through for every zone kind.
 */
#[CoversClass(ApiDomainRepository::class)]
class ApiDomainRepositoryLookupsTest extends TestCase
{
    private DnsBackendProviderInterface&MockObject $backend;
    private ApiDomainRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn($group, $key, $default = null) => $default);

        $this->backend = $this->createMock(DnsBackendProviderInterface::class);
        $this->backend->method('isApiBackend')->willReturn(true);
        $this->backend->method('getZoneNameById')->willReturnMap([
            [1, 'native.example.com'],
            [2, 'slave.example.com'],
            [3, 'consumer.example.com'],
            [99, null],
        ]);
        $this->backend->method('getZoneTypeById')->willReturnMap([
            [1, 'NATIVE'],
            [2, 'SLAVE'],
            [3, 'CONSUMER'],
            [99, 'NATIVE'],
        ]);
        $this->backend->method('getZoneMasterById')->willReturnMap([
            [1, null],
            [2, '192.0.2.1'],
            [3, '192.0.2.2'],
            [99, null],
        ]);

        $this->repository = new ApiDomainRepository($db, $config, $this->backend);
    }

    #[Test]
    public function zoneIdExistsAnswersFromTheLocalZoneRowOnly(): void
    {
        $this->backend->expects($this->never())->method('getZoneById');
        $this->backend->expects($this->never())->method('getZoneByName');

        $this->assertTrue($this->repository->zoneIdExists(1));
        $this->assertTrue($this->repository->zoneIdExists(3));
        $this->assertFalse($this->repository->zoneIdExists(99));
    }

    #[Test]
    public function getDomainNameByIdReturnsTheLocalNameOrNull(): void
    {
        $this->backend->expects($this->never())->method('getZoneById');

        $this->assertSame('slave.example.com', $this->repository->getDomainNameById(2));
        $this->assertNull($this->repository->getDomainNameById(99));
    }

    #[Test]
    public function getDomainTypeReturnsTheProviderKind(): void
    {
        $this->assertSame('NATIVE', $this->repository->getDomainType(1));
        $this->assertSame('SLAVE', $this->repository->getDomainType(2));
        $this->assertSame('CONSUMER', $this->repository->getDomainType(3));
        $this->assertSame('NATIVE', $this->repository->getDomainType(99));
    }

    #[Test]
    public function getDomainSlaveMasterReturnsTheMasterForSlaveZones(): void
    {
        $this->assertSame('192.0.2.1', $this->repository->getDomainSlaveMaster(2));
    }

    #[Test]
    public function getDomainSlaveMasterReturnsTheMasterForConsumerZones(): void
    {
        $this->assertSame('192.0.2.2', $this->repository->getDomainSlaveMaster(3));
    }

    #[Test]
    public function getDomainSlaveMasterReturnsNullWhenNoMasterIsStored(): void
    {
        $this->assertNull($this->repository->getDomainSlaveMaster(1));
        $this->assertNull($this->repository->getDomainSlaveMaster(99));
    }
}
