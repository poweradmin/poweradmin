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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\User\UsersController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;
use ReflectionMethod;

/**
 * The bulk users form posts every row. Accounts an identity provider or the web
 * server created may have no email, and saving the list must not trip over them.
 */
class UsersControllerEmailTest extends TestCase
{
    private function saveRow(string $storedEmail, string $postedEmail, bool &$validated): bool
    {
        $controller = $this->getMockBuilder(UsersController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run', 'hasPermission', 'getCurrentUserId', 'services', 'setValidationConstraints', 'doValidateRequest', 'setMessage'])
            ->getMock();
        $controller->method('getCurrentUserId')->willReturn(1);
        $controller->method('hasPermission')->willReturn(true);
        $controller->method('doValidateRequest')->willReturnCallback(function () use (&$validated): bool {
            $validated = true;
            return false;
        });

        $apiPermissions = $this->createMock(ApiPermissionService::class);
        $apiPermissions->method('canEditUser')->willReturn(true);
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('getUserById')->willReturn(['id' => 9, 'email' => $storedEmail]);
        $users = $this->createMock(UserManagementService::class);
        $users->method('updateUser')->willReturn(['success' => true]);

        $services = $this->createMock(ControllerServiceFactory::class);
        $services->method('apiPermissionService')->willReturn($apiPermissions);
        $services->method('userRepository')->willReturn($repository);
        $services->method('userManagementService')->willReturn($users);
        $controller->method('services')->willReturn($services);

        $posted = ['uid' => 9, 'username' => 'alice', 'fullname' => 'Alice', 'email' => $postedEmail, 'active' => 'on'];

        return (new ReflectionMethod(UsersController::class, 'updateUserRow'))->invoke($controller, $posted);
    }

    public function testAnUnchangedEmptyEmailDoesNotBlockTheRow(): void
    {
        $validated = false;

        $this->assertTrue($this->saveRow('', '', $validated));
        $this->assertFalse($validated);
    }

    public function testAChangedEmailIsStillValidated(): void
    {
        $validated = false;

        $this->assertFalse($this->saveRow('alice@example.com', 'not-an-address', $validated));
        $this->assertTrue($validated);
    }
}
