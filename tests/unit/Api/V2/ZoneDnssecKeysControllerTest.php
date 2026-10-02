<?php

namespace Poweradmin\Tests\Unit\Api\V2;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

class ZoneDnssecKeysControllerTest extends TestCase
{
    public function testAddingAKeyNeedsViewAccessAsWellAsDnssecManagement(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('zoneExists')->willReturn(true);
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canViewZone')->willReturn(false);
        $permissions->method('canManageDnssec')->willReturn(true);
        $api = $this->createMock(PowerdnsApiClient::class);
        $api->expects($this->never())->method($this->anything());

        $controller = new TestableZoneDnssecKeysController([], ['id' => 5]);
        $controller->setZoneRepository($zones);
        $controller->setApiPermissionService($permissions);
        $controller->setApiClient($api);
        $controller->setRequestBody('{"type":"csk","algorithm":"ecdsap256sha256"}');

        $response = $controller->callAddKey();

        $this->assertSame(403, $response->getStatusCode());
    }
}
