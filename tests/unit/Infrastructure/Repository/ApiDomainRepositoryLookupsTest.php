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
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Repository\ApiDomainRepository;
use TestHelpers\FakeConfiguration;

/**
 * The API-mode existence and name lookups answer from the local zones row and never ask PowerDNS.
 */
#[CoversClass(ApiDomainRepository::class)]
class ApiDomainRepositoryLookupsTest extends TestCase
{
    private DnsBackendProviderInterface&MockObject $backend;
    private ApiDomainRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backend = $this->createMock(DnsBackendProviderInterface::class);
        $this->backend->method('getZoneNameById')->willReturnMap([
            [1, 'native.example.com'],
            [3, 'consumer.example.com'],
            [99, null],
        ]);

        $this->repository = new ApiDomainRepository(
            $this->createStub(PDO::class),
            new FakeConfiguration(),
            $this->backend
        );
    }

    #[Test]
    public function zoneIdExistsAnswersFromTheLocalZoneRowOnly(): void
    {
        $this->backend->expects($this->never())->method('getZoneById');

        $this->assertTrue($this->repository->zoneIdExists(1));
        $this->assertTrue($this->repository->zoneIdExists(3));
        $this->assertFalse($this->repository->zoneIdExists(99));
    }

    #[Test]
    public function getDomainNameByIdReturnsTheLocalNameOrNull(): void
    {
        $this->backend->expects($this->never())->method('getZoneById');

        $this->assertSame('consumer.example.com', $this->repository->getDomainNameById(3));
        $this->assertNull($this->repository->getDomainNameById(99));
    }
}
