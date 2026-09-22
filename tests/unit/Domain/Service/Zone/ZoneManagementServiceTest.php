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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RepositoryFactoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Domain\Service\Validation\Refusal;
use TestHelpers\FakeConfiguration;

#[CoversClass(ZoneManagementService::class)]
class ZoneManagementServiceTest extends TestCase
{
    private ZoneManagementService $service;
    private ZoneRepositoryInterface&MockObject $zoneRepository;
    private DomainRepositoryInterface&MockObject $domainRepository;
    private FakeConfiguration $config;
    private string $originalErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress error_log output during tests
        $this->originalErrorLog = ini_get('error_log') ?: '';
        ini_set('error_log', '/dev/null');

        $this->zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $this->domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $this->config = new FakeConfiguration();

        $this->service = new ZoneManagementService(
            $this->zoneRepository,
            $this->config,
            $this->createMock(RepositoryFactoryInterface::class),
            $this->createMock(PermissionService::class),
            $this->createMock(RecordChangeWriterInterface::class),
            $this->createMock(DomainManagerInterface::class),
            $this->createMock(ZoneTemplateService::class),
            domainRepository: $this->domainRepository
        );
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalErrorLog);
        parent::tearDown();
    }

    // ========== createZone validation tests ==========

    #[Test]
    public function testCreateZoneRejectsWhenOwnerAndGroupsBothMissing(): void
    {
        $result = $this->service->createZone(
            'example.com',
            'MASTER',
            null,
            '',
            'none',
            false,
            []
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('At least one user or group must be assigned as owner', $result['message']);
        $this->assertSame(Refusal::INVALID_INPUT, $result['refusal']);
    }

    /**
     * Catalog kinds have no masters plumbing on the API path: createZone() only
     * requires a primary when the type is literally SLAVE. Widening $validTypes
     * without widening that guard would create masterless consumer zones.
     */
    #[Test]
    public function testCreateZoneRejectsCatalogZoneKinds(): void
    {
        foreach (['CONSUMER', 'PRODUCER'] as $type) {
            $result = $this->service->createZone(
                'catalog.example.com',
                $type,
                1,
                '',
                'none',
                false,
                []
            );

            $this->assertFalse($result['success'], "$type must be rejected");
            $this->assertSame(Refusal::INVALID_INPUT, $result['refusal']);
            $this->assertStringContainsString('Invalid zone type', $result['message']);
        }
    }

    // ========== updateZone tests ==========

    #[Test]
    public function testUpdateZoneReturnsErrorWhenZoneNotFound(): void
    {
        $this->domainRepository->method('zoneIdExists')
            ->with(999)
            ->willReturn(false);

        $result = $this->service->updateZone(999, ['name' => 'new.example.com']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Zone not found', $result['message']);
        $this->assertSame(Refusal::NOT_FOUND, $result['refusal']);
    }

    #[Test]
    public function testUpdateZoneReturnsBadRequestOnInvalidArgument(): void
    {
        $this->domainRepository->method('zoneIdExists')
            ->with(1)
            ->willReturn(true);

        $this->zoneRepository->method('updateZone')
            ->with(1, ['type' => 'BOGUS'])
            ->willThrowException(new \InvalidArgumentException('Invalid zone type'));

        $result = $this->service->updateZone(1, ['type' => 'BOGUS']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid zone type', $result['message']);
        $this->assertSame(Refusal::INVALID_INPUT, $result['refusal']);
    }

    #[Test]
    public function testUpdateZoneReturnsErrorWhenUpdateFails(): void
    {
        $this->domainRepository->method('zoneIdExists')
            ->with(1)
            ->willReturn(true);

        $this->zoneRepository->method('updateZone')
            ->with(1, ['name' => 'new.example.com'])
            ->willReturn(false);

        $result = $this->service->updateZone(1, ['name' => 'new.example.com']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to update zone', $result['message']);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result['refusal']);
    }

    #[Test]
    public function testUpdateZoneReturnsSuccessWhenUpdateSucceeds(): void
    {
        $this->domainRepository->method('zoneIdExists')
            ->with(1)
            ->willReturn(true);

        $this->zoneRepository->method('updateZone')
            ->with(1, ['type' => 'NATIVE'])
            ->willReturn(true);

        $result = $this->service->updateZone(1, ['type' => 'NATIVE']);

        $this->assertTrue($result['success']);
        $this->assertEquals('Zone updated successfully', $result['message']);
    }

    // ========== deleteZone tests ==========

    #[Test]
    public function testDeleteZoneReturnsErrorWhenZoneNotFound(): void
    {
        $this->domainRepository->method('zoneIdExists')
            ->with(999)
            ->willReturn(false);

        $result = $this->service->deleteZone(999);

        $this->assertFalse($result['success']);
        $this->assertEquals('Zone not found', $result['message']);
        $this->assertSame(Refusal::NOT_FOUND, $result['refusal']);
        $this->assertSame(ZoneManagementService::ERR_NOT_FOUND, $result['code']);
    }

    #[Test]
    public function testDeleteZoneReturnsErrorWhenDeleteFails(): void
    {
        $this->domainRepository->method('zoneIdExists')
            ->with(1)
            ->willReturn(true);

        $this->zoneRepository->method('deleteZone')
            ->with(1)
            ->willReturn(false);

        $result = $this->service->deleteZone(1);

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to delete zone', $result['message']);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result['refusal']);
    }

    #[Test]
    public function testDeleteZoneReturnsSuccessWhenDeleteSucceeds(): void
    {
        $this->domainRepository->method('zoneIdExists')
            ->with(1)
            ->willReturn(true);

        $this->zoneRepository->method('deleteZone')
            ->with(1)
            ->willReturn(true);

        $result = $this->service->deleteZone(1);

        $this->assertTrue($result['success']);
        $this->assertEquals('Zone deleted successfully', $result['message']);
    }

    #[Test]
    public function testDeleteZoneLeavesSyncRecordsToTheForeignKeyCascade(): void
    {
        // zone_template_sync.zone_id cascades from zones.id. Resolving that id here from a
        // domains.id used to clear an unrelated zone's sync state, so only the repository
        // delete is issued; the service holds no connection of its own any more.
        $this->domainRepository->method('zoneIdExists')->with(7)->willReturn(true);
        $this->zoneRepository->expects($this->once())->method('deleteZone')->with(7)->willReturn(true);

        $result = $this->service->deleteZone(7);

        $this->assertTrue($result['success']);
    }
}
