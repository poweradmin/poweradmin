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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\AbstractApiController;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Controller\Api\V2\ZoneOwnersController;
use Poweradmin\BaseController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A caller may only name zone owners from the users the ownership picker offers.
 */
class ZoneOwnersControllerAssignTest extends TestCase
{
    public function testSingleAddOfAUserTheCallerCannotPickIsRefused(): void
    {
        $response = $this->add(['user_id' => 7], false);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testBatchAddRefusesTheWholeRequestWhenOneUserIsNotPickable(): void
    {
        $response = $this->add(['user_ids' => [3, 7]], false);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAPickableUserIsStillAdded(): void
    {
        $response = $this->add(['user_id' => 7], true);

        $this->assertSame(201, $response->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function add(array $body, bool $pickable): JsonResponse
    {
        $controller = (new \ReflectionClass(ZoneOwnersController::class))->newInstanceWithoutConstructor();

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('zoneExists')->willReturn(true);
        $zones->method('isUserZoneOwner')->willReturn(false);
        $zones->expects($pickable ? $this->atLeastOnce() : $this->never())->method('addOwnerToZone');

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $permissions->method('canAssignZoneOwner')->willReturn($pickable);

        $users = $this->createMock(DbUserRepository::class);
        $users->method('getUserById')->willReturn(['id' => 7]);

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn('both');

        foreach (
            [
                [ZoneOwnersController::class, 'zoneRepository', $zones],
                [ZoneOwnersController::class, 'apiPermissionService', $permissions],
                [ZoneOwnersController::class, 'userRepository', $users],
                [PublicApiController::class, 'pathParameters', ['id' => '1']],
                [PublicApiController::class, 'authenticatedUserId', 5],
                [AbstractApiController::class, 'request', new Request([], [], [], [], [], [], json_encode($body))],
                [BaseController::class, 'config', $config],
            ] as [$class, $property, $value]
        ) {
            (new ReflectionProperty($class, $property))->setValue($controller, $value);
        }

        return (new ReflectionMethod(ZoneOwnersController::class, 'addOwner'))->invoke($controller);
    }
}
