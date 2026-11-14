<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Api\V1;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V1\ZonesController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ZoneManagementService;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * PUT /api/v1/zones/{id} validates the master list like create does.
 */
class V1ZoneMasterUpdateTest extends TestCase
{
    public function testZoneUpdateRefusesAMalformedMasterList(): void
    {
        $this->assertSame(400, $this->updateZone(['master' => 'ns1.example.org; evil'], false));
    }

    public function testZoneUpdateAcceptsAValidMasterList(): void
    {
        $this->assertSame(200, $this->updateZone(['master' => '192.0.2.1, 192.0.2.2:5300'], true));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function updateZone(array $body, bool $expectWrite): int
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $permissions->method('canCreateZone')->willReturn(true);

        $zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $zoneRepository->method('getZoneById')->willReturn(['id' => 3, 'name' => 'a.example', 'type' => 'SLAVE', 'master' => '', 'account' => '']);

        $service = $this->createMock(ZoneManagementService::class);
        $service->expects($expectWrite ? $this->once() : $this->never())->method('updateZone')->willReturn(['success' => true]);

        $controller = (new ReflectionClass(ZonesController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'permissionService', $permissions);
        $this->inject($controller, 'zoneRepository', $zoneRepository);
        $this->inject($controller, 'zoneManagementService', $service);
        $this->inject($controller, 'request', $this->jsonRequest($body));
        $this->inject($controller, 'pathParameters', ['id' => 3]);
        $this->inject($controller, 'authenticatedUserId', 7);

        return (new ReflectionClass($controller))->getMethod('updateZone')->invoke($controller)->getStatusCode();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    }

    private function inject(object $controller, string $property, mixed $value): void
    {
        (new ReflectionClass($controller))->getProperty($property)->setValue($controller, $value);
    }
}
