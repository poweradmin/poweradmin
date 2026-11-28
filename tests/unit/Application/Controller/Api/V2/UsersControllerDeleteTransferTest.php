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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\AbstractApiController;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Controller\Api\V2\UsersController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\UserManagementService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

/**
 * transfer_to_user_id must be a real id: an array or boolean is refused, not cast to user 1.
 */
class UsersControllerDeleteTransferTest extends TestCase
{
    public static function badTransferProvider(): array
    {
        return [
            'array' => ['{"transfer_to_user_id": [7]}'],
            'true' => ['{"transfer_to_user_id": true}'],
            'word' => ['{"transfer_to_user_id": "abc"}'],
            'zero' => ['{"transfer_to_user_id": 0}'],
        ];
    }

    #[DataProvider('badTransferProvider')]
    public function testInvalidTransferTargetIsRefusedBeforeDeleting(string $body): void
    {
        $service = $this->createMock(UserManagementService::class);
        $service->expects($this->never())->method('deleteUser');

        $response = $this->delete($body, $service);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testNumericStringTransferTargetIsPassedAsInteger(): void
    {
        $service = $this->createMock(UserManagementService::class);
        $service->expects($this->once())->method('deleteUser')->with(9, 7, 1)
            ->willReturn(['success' => true, 'message' => 'ok', 'zones_affected' => 2]);

        $this->assertSame(200, $this->delete('{"transfer_to_user_id": "7"}', $service)->getStatusCode());
    }

    public function testMissingTransferTargetStaysNull(): void
    {
        $service = $this->createMock(UserManagementService::class);
        $service->expects($this->once())->method('deleteUser')->with(9, null, 1)
            ->willReturn(['success' => true, 'message' => 'ok']);

        $this->assertSame(200, $this->delete('', $service)->getStatusCode());
    }

    private function delete(string $body, UserManagementService $service): object
    {
        $permission = $this->createMock(ApiPermissionService::class);
        $permission->method('canDeleteUser')->willReturn(true);

        $controller = (new ReflectionClass(UsersController::class))->newInstanceWithoutConstructor();
        foreach (
            [
                [PublicApiController::class, 'authenticatedUserId', 1],
                [PublicApiController::class, 'pathParameters', ['id' => 9]],
                [UsersController::class, 'apiPermissionService', $permission],
                [UsersController::class, 'userManagementService', $service],
                [AbstractApiController::class, 'request', new Request([], [], [], [], [], [], $body)],
            ] as [$class, $property, $value]
        ) {
            (new ReflectionProperty($class, $property))->setValue($controller, $value);
        }

        return (new ReflectionMethod(UsersController::class, 'deleteUser'))->invoke($controller);
    }
}
