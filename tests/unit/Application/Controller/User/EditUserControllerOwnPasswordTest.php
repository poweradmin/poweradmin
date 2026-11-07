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

namespace Poweradmin\Tests\Unit\Application\Controller\User;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\User\EditUserController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;

/**
 * Your own password is changed on the change password page, which asks for the
 * current one; the profile form must not let a borrowed session skip that.
 */
#[CoversClass(EditUserController::class)]
class EditUserControllerOwnPasswordTest extends SeamControllerTestCase
{
    private function preparedFor(int $editId): array
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(
            fn(int $id, string $permission): bool => in_array($permission, [Permission::PERM_USER_EDIT_OTHERS, Permission::PERM_USER_PASSWD_EDIT_OTHERS], true)
        );
        $this->factory->method('permissionService')->willReturn($permissions);
        $apiPermissions = $this->createMock(ApiPermissionService::class);
        $apiPermissions->method('canEditUserPassword')->willReturn(true);
        $this->factory->method('apiPermissionService')->willReturn($apiPermissions);

        $this->post(['username' => 'jane', 'fullname' => 'Jane', 'email' => 'jane@example.org', 'description' => '', 'password' => 'NewSecret123']);
        $controller = new EditUserController($this->requestData(), true, $this->environment($this->configure()));
        $stored = ['username' => 'jane', 'fullname' => 'Jane', 'email' => 'jane@example.org', 'auth_type' => 'sql', 'use_ldap' => 0];

        return (new ReflectionMethod(EditUserController::class, 'prepareUserData'))->invoke($controller, $editId, $stored, self::USER_ID);
    }

    public function testThePasswordFieldIsIgnoredOnYourOwnProfile(): void
    {
        $this->assertArrayNotHasKey('password', $this->preparedFor(self::USER_ID));
    }

    public function testAnotherUsersPasswordIsStillSetWithTheRight(): void
    {
        $this->assertSame('NewSecret123', $this->preparedFor(self::USER_ID + 100)['password'] ?? null);
    }
}
