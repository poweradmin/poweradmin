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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ZonesCommentsDisabledTest extends TestCase
{
    private function controller(bool $commentsOn, ?string $body, ZoneRepositoryInterface $zones, ?ApiPermissionService $permissions = null): ZonesController
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $g, string $k, $d = null) => $g === 'interface' && $k === 'show_zone_comments' ? $commentsOn : $d
        );

        $controller = (new ReflectionClass(ZonesController::class))->newInstanceWithoutConstructor();
        $set = function (string $class, string $name, mixed $value) use ($controller): void {
            $p = new \ReflectionProperty($class, $name);
            $p->setValue($controller, $value);
        };
        $set(\Poweradmin\BaseController::class, 'config', $config);
        $set(\Poweradmin\Application\Controller\Api\AbstractApiController::class, 'request', Request::create('/', 'POST', [], [], [], [], $body ?? ''));
        $set(\Poweradmin\Application\Controller\Api\PublicApiController::class, 'pathParameters', ['id' => '5']);
        $set(\Poweradmin\Application\Controller\Api\PublicApiController::class, 'authenticatedUserId', 3);
        $set(ZonesController::class, 'zoneRepository', $zones);
        if ($permissions !== null) {
            $set(ZonesController::class, 'permissionService', $permissions);
        }

        return $controller;
    }

    private function call(ZonesController $controller, string $method): JsonResponse
    {
        return (new \ReflectionMethod(ZonesController::class, $method))->invoke($controller);
    }

    public function testCreateWithADescriptionIsRefusedBeforeAnythingIsCreated(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->expects($this->never())->method('updateZoneComment');
        $controller = $this->controller(false, json_encode(['name' => 'example.com', 'type' => 'NATIVE', 'description' => 'note']), $zones);

        $response = $this->call($controller, 'createZone');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Zone comments feature is disabled in configuration', $response->getContent());
    }

    public function testUpdateWithAChangedDescriptionIsRefused(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('zoneIdExists')->willReturn(true);
        $zones->method('getZoneComment')->willReturn('old note');
        $zones->expects($this->never())->method('updateZoneComment');
        $controller = $this->controller(false, json_encode(['description' => 'note']), $zones, $this->permissions());

        $response = $this->call($controller, 'updateZone');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Zone comments feature is disabled in configuration', $response->getContent());
    }

    public function testUpdateResendingTheStoredDescriptionIsAllowed(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('zoneIdExists')->willReturn(true);
        $zones->method('getZoneById')->willReturn(['id' => 5, 'name' => 'example.com', 'type' => 'NATIVE']);
        $zones->method('getZoneComment')->willReturn('old note');
        $controller = $this->controller(false, json_encode(['description' => 'old note']), $zones, $this->permissions());

        $response = $this->call($controller, 'updateZone');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('old note', json_decode($response->getContent(), true)['data']['zone']['description']);
    }

    public function testZoneDetailKeepsReturningTheStoredDescription(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZoneById')->willReturn(['id' => 5, 'name' => 'example.com', 'type' => 'NATIVE']);
        $zones->method('getZoneComment')->willReturn('stored note');
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canViewZone')->willReturn(true);

        $controller = $this->controller(false, null, $zones, $permissions);
        $data = json_decode($this->call($controller, 'getZone')->getContent(), true);
        $this->assertSame('stored note', $data['data']['zone']['description']);
    }

    private function permissions(): ApiPermissionService
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $permissions->method('hasZoneContentEditPermission')->willReturn(true);

        return $permissions;
    }
}
