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
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * transfer_to_user_id picks the new owner of a deleted user's zones, so a wrong shape must be refused.
 */
class UsersControllerDeleteTransferTest extends TestCase
{
    /** @return array<string, array{mixed}> */
    public static function badValues(): array
    {
        return [
            'array' => [[7]],
            'bool' => [true],
            'text' => ['abc'],
            'float' => [1.5],
        ];
    }

    #[DataProvider('badValues')]
    public function testBadTransferTargetIsRefusedBeforeAnyDelete(mixed $value): void
    {
        $response = $this->delete(['transfer_to_user_id' => $value], null);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testNumericStringTargetIsPassedAsInteger(): void
    {
        $response = $this->delete(['transfer_to_user_id' => '7'], 7);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNoTargetStillDeletesWithoutTransfer(): void
    {
        $response = $this->delete([], null, true);

        $this->assertSame(200, $response->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function delete(array $body, ?int $expectedTransfer, bool $expectDelete = false): JsonResponse
    {
        $controller = (new \ReflectionClass(UsersController::class))->newInstanceWithoutConstructor();

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canDeleteUser')->willReturn(true);

        $service = $this->createMock(UserManagementService::class);
        $reaches = $expectDelete || $expectedTransfer !== null;
        $service->expects($reaches ? $this->once() : $this->never())
            ->method('deleteUser')
            ->with(3, $expectedTransfer)
            ->willReturn(['success' => true, 'message' => 'deleted', 'zones_affected' => 0]);

        foreach (
            [
                [UsersController::class, 'apiPermissionService', $permissions],
                [UsersController::class, 'userManagementService', $service],
                [PublicApiController::class, 'pathParameters', ['id' => '3']],
                [PublicApiController::class, 'authenticatedUserId', 5],
                [AbstractApiController::class, 'request', new Request([], [], [], [], [], [], json_encode($body))],
            ] as [$class, $property, $value]
        ) {
            (new ReflectionProperty($class, $property))->setValue($controller, $value);
        }

        return (new ReflectionMethod(UsersController::class, 'deleteUser'))->invoke($controller);
    }
}
