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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\UsersController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\UserManagementService;
use ReflectionMethod;

/**
 * The bulk users form keeps username and active flag off a self-edit without
 * user_edit_others, the same as the single-user form and the API (#1327).
 */
class UsersControllerSelfEditTest extends TestCase
{
    private const CALLER_ID = 7;

    /**
     * @return array<string, mixed> the input handed to UserManagementService::updateUser()
     */
    private function updateRow(int $targetId, bool $editOthers): array
    {
        $controller = $this->getMockBuilder(UsersController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run', 'hasPermission', 'getCurrentUserId', 'createApiPermissionService', 'createUserManagementService', 'setValidationConstraints', 'doValidateRequest', 'setMessage'])
            ->getMock();
        $controller->method('getCurrentUserId')->willReturn(self::CALLER_ID);
        $controller->method('hasPermission')->willReturnCallback(
            fn(string $permission): bool => $permission === Permission::PERM_USER_EDIT_OTHERS && $editOthers
        );
        $controller->method('doValidateRequest')->willReturn(true);

        $apiPermissions = $this->createMock(ApiPermissionService::class);
        $apiPermissions->method('canEditUser')->willReturn(true);
        $controller->method('createApiPermissionService')->willReturn($apiPermissions);

        $captured = [];
        $users = $this->createMock(UserManagementService::class);
        $users->method('updateUser')->willReturnCallback(function (int $id, array $input) use (&$captured): array {
            $captured = $input;
            return ['success' => true];
        });
        $controller->method('createUserManagementService')->willReturn($users);

        $posted = ['uid' => $targetId, 'username' => 'renamed', 'fullname' => 'Name', 'email' => 'a@example.com', 'active' => 'on'];
        (new ReflectionMethod(UsersController::class, 'updateUserRow'))->invoke($controller, $posted);

        return $captured;
    }

    public function testSelfEditWithoutEditOthersKeepsUsernameAndActiveStored(): void
    {
        $input = $this->updateRow(self::CALLER_ID, false);

        $this->assertArrayNotHasKey('username', $input);
        $this->assertArrayNotHasKey('active', $input);
        $this->assertSame('Name', $input['fullname']);
    }

    public function testEditOthersGrantWritesEveryField(): void
    {
        $this->assertSame('renamed', $this->updateRow(self::CALLER_ID, true)['username']);
        $this->assertSame('renamed', $this->updateRow(9, false)['username']);
    }
}
