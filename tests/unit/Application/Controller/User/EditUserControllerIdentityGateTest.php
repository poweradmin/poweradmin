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
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\User\EditUserController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;

/**
 * A new username or email is a way into another account (a password reset
 * mail goes to the address, LDAP matches the name), so it needs the right to
 * set that account's password.
 */
#[CoversClass(EditUserController::class)]
class EditUserControllerIdentityGateTest extends SeamControllerTestCase
{
    private const TARGET_ID = 9;

    /** @return array{0: EditUserController&\PHPUnit\Framework\MockObject\MockObject, 1: UserManagementService&\PHPUnit\Framework\MockObject\MockObject} */
    private function submit(bool $mayEditPasswords, array $fields): array
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(
            fn(int $id, string $permission): bool => $permission === Permission::PERM_USER_EDIT_OTHERS
        );
        $this->factory->method('permissionService')->willReturn($permissions);
        $apiPermissions = $this->createMock(ApiPermissionService::class);
        $apiPermissions->method('canEditUserPassword')->willReturn($mayEditPasswords);
        $this->factory->method('apiPermissionService')->willReturn($apiPermissions);

        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('getUserDetailList')->willReturn([[
            'username' => 'jane', 'fullname' => 'Jane', 'email' => 'jane@example.org', 'auth_type' => 'sql', 'use_ldap' => 0, 'tpl_id' => 1,
        ]]);
        $this->factory->method('userRepository')->willReturn($repository);
        $users = $this->createMock(UserManagementService::class);
        $this->factory->method('userManagementService')->willReturn($users);

        $this->post($fields + ['username' => 'jane', 'fullname' => 'Jane', 'email' => 'jane@example.org', 'description' => '']);
        $controller = $this->getMockBuilder(EditUserController::class)
            ->setConstructorArgs([$this->requestData(), true, $this->environment($this->configure())])
            ->onlyMethods(['showUserEditForm'])
            ->getMock();

        return [$controller, $users];
    }

    private function update(EditUserController $controller): void
    {
        (new ReflectionMethod(EditUserController::class, 'updateUser'))->invoke($controller, self::TARGET_ID, []);
    }

    public function testANewEmailOrUsernameIsRefusedWithoutThePasswordRight(): void
    {
        foreach ([['email' => 'mallory@example.org'], ['username' => 'mallory']] as $fields) {
            [$controller, $users] = $this->submit(false, $fields);
            $users->expects($this->never())->method('updateUser');
            $controller->expects($this->once())->method('showUserEditForm');

            $this->update($controller);

            $this->assertSame([['error', 'Authentication settings can only be changed by an administrator']], $this->messagesFor('edit_user'));
        }
    }

    public function testUnchangedIdentityStillSavesWithoutThePasswordRight(): void
    {
        [$controller, $users] = $this->submit(false, ['fullname' => 'Jane Q']);
        $users->expects($this->once())->method('updateUser')->willReturn(['success' => true]);

        try {
            $this->update($controller);
        } catch (RequestHalted $halt) {
            $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        }

        $this->assertSame([], $this->messagesFor('edit_user'));
    }
}
