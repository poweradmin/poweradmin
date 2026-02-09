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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

#[CoversClass(ZoneManagementService::class)]
class ZoneManagementServiceTest extends TestCase
{
    private ZoneManagementService $service;
    private ZoneRepositoryInterface&MockObject $zoneRepository;
    private ConfigurationManager&MockObject $config;
    private PDOCommon&MockObject $db;
    private string $originalErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress error_log output during tests
        $this->originalErrorLog = ini_get('error_log') ?: '';
        ini_set('error_log', '/dev/null');

        $this->zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->db = $this->createMock(PDOCommon::class);

        $this->service = new ZoneManagementService(
            $this->zoneRepository,
            $this->config,
            $this->db
        );
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalErrorLog);
        parent::tearDown();
    }

    private function setupDbPrepareForDelete(): void
    {
        // Mock db->prepare for ZoneTemplateSyncService cleanup
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $this->db->method('prepare')->willReturn($stmt);
    }

    // ========== updateZone tests ==========

    #[Test]
    public function testUpdateZoneReturnsErrorWhenZoneNotFound(): void
    {
        $this->zoneRepository->method('zoneIdExists')
            ->with(999)
            ->willReturn(false);

        $result = $this->service->updateZone(999, ['name' => 'new.example.com']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Zone not found', $result['message']);
    }

    #[Test]
    public function testUpdateZoneReturnsErrorWhenUpdateFails(): void
    {
        $this->zoneRepository->method('zoneIdExists')
            ->with(1)
            ->willReturn(true);

        $this->zoneRepository->method('updateZone')
            ->with(1, ['name' => 'new.example.com'])
            ->willReturn(false);

        $result = $this->service->updateZone(1, ['name' => 'new.example.com']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to update zone', $result['message']);
    }

    #[Test]
    public function testUpdateZoneReturnsSuccessWhenUpdateSucceeds(): void
    {
        $this->zoneRepository->method('zoneIdExists')
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
        $this->zoneRepository->method('zoneIdExists')
            ->with(999)
            ->willReturn(false);

        $result = $this->service->deleteZone(999);

        $this->assertFalse($result['success']);
        $this->assertEquals('Zone not found', $result['message']);
    }

    #[Test]
    public function testDeleteZoneReturnsErrorWhenDeleteFails(): void
    {
        $this->setupDbPrepareForDelete();

        $this->zoneRepository->method('zoneIdExists')
            ->with(1)
            ->willReturn(true);

        $this->zoneRepository->method('deleteZone')
            ->with(1)
            ->willReturn(false);

        $result = $this->service->deleteZone(1);

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to delete zone', $result['message']);
    }

    #[Test]
    public function testDeleteZoneReturnsSuccessWhenDeleteSucceeds(): void
    {
        $this->setupDbPrepareForDelete();

        $this->zoneRepository->method('zoneIdExists')
            ->with(1)
            ->willReturn(true);

        $this->zoneRepository->method('deleteZone')
            ->with(1)
            ->willReturn(true);

        $result = $this->service->deleteZone(1);

        $this->assertTrue($result['success']);
        $this->assertEquals('Zone deleted successfully', $result['message']);
    }

    // ========== setDomainPermissions tests ==========

    #[Test]
    public function testSetDomainPermissionsReturnsErrorWhenDomainNotFound(): void
    {
        $this->zoneRepository->method('getZone')
            ->with(999)
            ->willReturn(null);

        $result = $this->service->setDomainPermissions(999, 1);

        $this->assertFalse($result['success']);
        $this->assertEquals('Domain not found', $result['message']);
    }

    #[Test]
    public function testSetDomainPermissionsReturnsSuccessWhenUserAlreadyOwner(): void
    {
        $this->zoneRepository->method('getZone')
            ->with(1)
            ->willReturn(['id' => 1, 'name' => 'example.com']);

        $this->zoneRepository->method('isUserZoneOwner')
            ->with(1, 5)
            ->willReturn(true);

        $result = $this->service->setDomainPermissions(1, 5);

        $this->assertTrue($result['success']);
        $this->assertEquals('User is already an owner of this domain', $result['message']);
        $this->assertEquals(1, $result['domain_id']);
        $this->assertEquals(5, $result['user_id']);
    }

    #[Test]
    public function testSetDomainPermissionsReturnsErrorWhenAddOwnerFails(): void
    {
        $this->zoneRepository->method('getZone')
            ->with(1)
            ->willReturn(['id' => 1, 'name' => 'example.com']);

        $this->zoneRepository->method('isUserZoneOwner')
            ->with(1, 5)
            ->willReturn(false);

        $this->zoneRepository->method('addOwnerToZone')
            ->with(1, 5)
            ->willReturn(false);

        $result = $this->service->setDomainPermissions(1, 5);

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to set domain permissions', $result['message']);
    }

    #[Test]
    public function testSetDomainPermissionsReturnsSuccessWhenOwnerAdded(): void
    {
        $this->zoneRepository->method('getZone')
            ->with(1)
            ->willReturn(['id' => 1, 'name' => 'example.com']);

        $this->zoneRepository->method('isUserZoneOwner')
            ->with(1, 5)
            ->willReturn(false);

        $this->zoneRepository->method('addOwnerToZone')
            ->with(1, 5)
            ->willReturn(true);

        $result = $this->service->setDomainPermissions(1, 5);

        $this->assertTrue($result['success']);
        $this->assertEquals('Domain permissions set successfully', $result['message']);
        $this->assertEquals(1, $result['domain_id']);
        $this->assertEquals(5, $result['user_id']);
    }

    #[Test]
    public function testSetDomainPermissionsWithDifferentUsersAndDomains(): void
    {
        $this->zoneRepository->method('getZone')
            ->with(100)
            ->willReturn(['id' => 100, 'name' => 'test-domain.org']);

        $this->zoneRepository->method('isUserZoneOwner')
            ->with(100, 42)
            ->willReturn(false);

        $this->zoneRepository->method('addOwnerToZone')
            ->with(100, 42)
            ->willReturn(true);

        $result = $this->service->setDomainPermissions(100, 42);

        $this->assertTrue($result['success']);
        $this->assertEquals(100, $result['domain_id']);
        $this->assertEquals(42, $result['user_id']);
    }
}
