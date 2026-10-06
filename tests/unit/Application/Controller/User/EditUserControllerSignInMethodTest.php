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
 * Pointing another account at LDAP or the web server hands its sign-in to whoever
 * holds that name there, so it needs the right to set that account's password.
 */
#[CoversClass(EditUserController::class)]
class EditUserControllerSignInMethodTest extends SeamControllerTestCase
{
    private const TARGET_ID = 9;

    private function controls(bool $mayEditPasswords): array
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(
            fn(int $id, string $permission): bool => $permission === Permission::PERM_USER_EDIT_OTHERS
        );
        $this->factory->method('permissionService')->willReturn($permissions);
        $apiPermissions = $this->createMock(ApiPermissionService::class);
        $apiPermissions->method('canEditUserPassword')->willReturn($mayEditPasswords);
        $this->factory->method('apiPermissionService')->willReturn($apiPermissions);

        $config = $this->configure(['ldap' => ['enabled' => true], 'remote_user' => ['enabled' => true]]);
        $controller = new EditUserController([], true, $this->environment($config));

        return [
            'ldap' => (new ReflectionMethod(EditUserController::class, 'ldapControlEditable'))->invoke($controller, self::TARGET_ID),
            'remote_user' => (new ReflectionMethod(EditUserController::class, 'remoteUserControlEditable'))->invoke($controller, self::TARGET_ID),
        ];
    }

    public function testEditingOthersWithoutThePasswordRightLocksTheSignInMethod(): void
    {
        $this->assertSame(['ldap' => false, 'remote_user' => false], $this->controls(false));
    }

    public function testThePasswordRightUnlocksIt(): void
    {
        $this->assertSame(['ldap' => true, 'remote_user' => true], $this->controls(true));
    }
}
