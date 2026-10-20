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
use Poweradmin\Application\Controller\User\UsersController;
use Poweradmin\Application\Service\Web\PaginationService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Characterizes what the users list hands its template for a ueberuser and for
 * a delegated admin (user_edit_others without user_is_ueberuser), so the
 * per-row edit decision matches the rule the bulk save applies.
 */
#[CoversClass(UsersController::class)]
class UsersControllerListTest extends SeamControllerTestCase
{
    private const SUPERUSER_ID = 1;
    private const OTHER_ID = 9;

    /** @var list<string> Permissions hasPermission() answers yes to */
    private array $granted = [];

    /** @var list<int> Users isAdmin() answers yes for */
    private array $admins = [self::SUPERUSER_ID];

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => in_array($permission, $this->granted, true));
        $permissions->method('getPermissionFlags')->willReturnCallback(function (int $userId, array $names): array {
            $flags = [];
            foreach ($names as $name) {
                $flags[$name] = in_array($name, $this->granted, true);
            }
            return $flags;
        });
        $permissions->method('isAdmin')->willReturnCallback(fn(int $userId): bool => in_array($userId, $this->admins, true));

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getTotalUserCount')->willReturn(3);
        $users->method('getUserDetailList')->willReturn([
            $this->row(self::SUPERUSER_ID, 'admin'),
            $this->row(self::USER_ID, self::USERNAME),
            $this->row(self::OTHER_ID, 'other'),
        ]);

        $templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $templates->method('listPermissionTemplates')->willReturn([['id' => 1, 'name' => 'Administrator']]);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('apiPermissionService')->willReturn(new ApiPermissionService($users, $permissions));
        $this->factory->method('userRepository')->willReturn($users);
        $this->factory->method('permissionTemplateRepository')->willReturn($templates);
        $this->factory->method('paginationService')->willReturn(new PaginationService());
    }

    /** @return array<string, mixed> */
    private function row(int $uid, string $username): array
    {
        return ['uid' => $uid, 'username' => $username, 'fullname' => '', 'email' => '', 'active' => 1, 'tpl_id' => 1, 'tpl_name' => 'x'];
    }

    /** @return array<string, mixed> */
    private function runAndRender(): array
    {
        $controller = new UsersController([], true, $this->environment($this->configure()));
        $controller->run();

        $this->assertSame('users.html', $this->output->rendered[0][0]);

        return $this->renderedParams();
    }

    public function testWithoutViewOrEditOthersTheListRedirectsHome(): void
    {
        $this->granted = [Permission::PERM_USER_EDIT_OWN];
        $controller = new UsersController([], true, $this->environment($this->configure()));

        try {
            $controller->run();
            $this->fail('expected a redirect');
        } catch (RequestHalted $halt) {
            $this->assertSame('/', $halt->target);
        }
        $this->assertSame([['error', 'You do not have permission to view the users list.']], $this->messagesFor('index'));
    }

    public function testUeberuserGetsTheRawFlagsAndEveryRow(): void
    {
        $this->granted = [
            Permission::PERM_USER_VIEW_OTHERS,
            Permission::PERM_USER_EDIT_OTHERS,
            Permission::PERM_USER_EDIT_TEMPL_PERM,
            Permission::PERM_USER_IS_UEBERUSER,
        ];
        $this->admins = [self::SUPERUSER_ID, self::USER_ID];

        $params = $this->runAndRender();

        $this->assertTrue($params['perm_is_godlike']);
        $this->assertSame(self::USER_ID, $params['session_userid']);
        $this->assertSame([
            Permission::PERM_USER_VIEW_OTHERS => true,
            Permission::PERM_USER_EDIT_OWN => false,
            Permission::PERM_USER_EDIT_OTHERS => true,
            Permission::PERM_USER_EDIT_TEMPL_PERM => true,
            Permission::PERM_USER_IS_UEBERUSER => true,
        ], $params['permissions']);
        $this->assertSame([self::SUPERUSER_ID, self::USER_ID, self::OTHER_ID], array_column($params['users'], 'uid'));
        $this->assertSame([true, true, true], array_column($params['users'], 'can_edit'));
        $this->assertSame([true, true, true], array_column($params['users'], 'can_change_template'));
    }

    public function testDelegatedAdminGetsTheRawFlagsWithoutUeberuser(): void
    {
        $this->granted = [Permission::PERM_USER_VIEW_OTHERS, Permission::PERM_USER_EDIT_OTHERS];

        $params = $this->runAndRender();

        $this->assertFalse($params['perm_is_godlike']);
        $this->assertFalse($params['permissions'][Permission::PERM_USER_IS_UEBERUSER]);
        $this->assertTrue($params['permissions'][Permission::PERM_USER_EDIT_OTHERS]);
        $this->assertFalse($params['permissions'][Permission::PERM_USER_EDIT_TEMPL_PERM]);
        $this->assertCount(3, $params['users']);
        // The ueberuser row is read-only, as the bulk save refuses it
        $this->assertSame([false, true, true], array_column($params['users'], 'can_edit'));
        $this->assertSame([false, false, false], array_column($params['users'], 'can_change_template'));
    }

    public function testTemplateChangeNeedsBothTheRowAndTheTemplatePermission(): void
    {
        $this->granted = [Permission::PERM_USER_VIEW_OTHERS, Permission::PERM_USER_EDIT_OTHERS, Permission::PERM_USER_EDIT_TEMPL_PERM];

        $params = $this->runAndRender();

        $this->assertSame([false, true, true], array_column($params['users'], 'can_edit'));
        $this->assertSame([false, true, true], array_column($params['users'], 'can_change_template'));
    }

    public function testSelfEditOnlyMarksTheOwnRow(): void
    {
        $this->granted = [Permission::PERM_USER_VIEW_OTHERS, Permission::PERM_USER_EDIT_OWN];

        $params = $this->runAndRender();

        $this->assertSame([false, true, false], array_column($params['users'], 'can_edit'));
    }
}
