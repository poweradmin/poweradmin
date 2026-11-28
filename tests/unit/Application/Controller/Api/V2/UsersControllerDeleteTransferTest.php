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
use Poweradmin\Application\Controller\Api\V2\UsersController;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;

/**
 * DELETE /api/v2/users/{id}: a transfer_to_user_id that is not a whole number must be
 * refused, not cast to an id (an array or true would otherwise become user 1).
 */
class UsersControllerDeleteTransferTest extends V2ControllerTestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function badTargets(): array
    {
        return [
            'array' => [[7]],
            'true' => [true],
            'fractional string' => ['1.5'],
            'word' => ['abc'],
        ];
    }

    #[DataProvider('badTargets')]
    public function testAMalformedTransferTargetIsRefusedAndNothingIsDeleted(mixed $value): void
    {
        $service = $this->createMock(UserManagementService::class);
        $service->expects($this->never())->method('deleteUser');

        $response = $this->send($service, ['transfer_to_user_id' => $value]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('transfer_to_user_id must be a numeric ID', $this->messageOf($response));
    }

    public function testANumericStringIsAccepted(): void
    {
        $service = $this->createMock(UserManagementService::class);
        $service->expects($this->once())->method('deleteUser')->with(5, 7, 100)->willReturn(['success' => true, 'message' => 'ok']);

        $this->assertSame(200, $this->send($service, ['transfer_to_user_id' => '7'])->getStatusCode());
    }

    public function testWithoutTheFieldNoTransferIsRequested(): void
    {
        $service = $this->createMock(UserManagementService::class);
        $service->expects($this->once())->method('deleteUser')->with(5, null, 100)->willReturn(['success' => true, 'message' => 'ok']);

        $this->assertSame(200, $this->send($service, [])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(UserManagementService $service, array $body): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $controller = $this->bareController(UsersController::class);
        $this->injectBaseCollaborators($controller, 'DELETE', $body);

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canDeleteUser')->willReturn(true);

        $lookup = $this->createMock(UserLookupInterface::class);
        $lookup->method('getUserById')->willReturn(['id' => 5, 'username' => 'carol']);

        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'userManagementService', $service);
        $this->inject($controller, 'userRepository', $lookup);
        $this->inject($controller, 'pathParameters', ['id' => 5]);
        $this->inject($controller, 'authenticatedUserId', 100);

        return $this->callHandler($controller, 'deleteUser');
    }
}
