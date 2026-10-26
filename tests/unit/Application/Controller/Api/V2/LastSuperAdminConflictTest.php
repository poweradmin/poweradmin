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

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\Api\V2\GroupMembersController;
use Poweradmin\Application\Controller\Api\V2\GroupsController;
use Poweradmin\Application\Controller\Api\V2\PermissionTemplatesController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\User\GroupMembershipService;
use Poweradmin\Application\Service\User\GroupService;
use Poweradmin\Application\Service\User\PermissionTemplateWriteService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Model\UberuserChange;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\UserPermissionReadInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\User\LastSuperAdminGuard;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The v2 group, member and template writes answer 409 with the guard's message when
 * the service refuses because the change would leave no active super admin.
 */
#[CoversClass(GroupMembersController::class)]
#[CoversClass(GroupsController::class)]
#[CoversClass(PermissionTemplatesController::class)]
class LastSuperAdminConflictTest extends V2ControllerTestCase
{
    private const GROUP_ID = 3;
    private const USER_ID = 5;
    private const TEMPLATE_ID = 7;

    private PermissionTemplateRepositoryInterface $templates;

    protected function setUp(): void
    {
        $this->templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $this->templates->method('validateTemplateType')->willReturn(true);
        $this->templates->method('getPermissionTemplateDetails')
            ->willReturn(['id' => self::TEMPLATE_ID, 'name' => 'Admins', 'template_type' => 'user']);
    }

    protected function stubServiceFactory(): ControllerServiceFactory
    {
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('userRepository')->willReturn($this->stubUsers());
        $factory->method('permissionTemplateRepository')->willReturn($this->templates);

        return $factory;
    }

    public function testRemovingTheLastSuperAdminFromAGroupIsAConflict(): void
    {
        $membership = $this->createMock(GroupMembershipService::class);
        $membership->method('removeUserFromGroup')->with(self::GROUP_ID, self::USER_ID)
            ->willReturn($this->refusalFor(UberuserChange::memberRemoved(self::GROUP_ID, self::USER_ID)));

        $controller = $this->bareController(GroupMembersController::class);
        $this->injectBaseCollaborators($controller, 'DELETE');
        $this->inject($controller, 'membershipService', $membership);
        $this->inject($controller, 'apiPermissionService', $this->allowAll());
        $this->inject($controller, 'pathParameters', ['id' => self::GROUP_ID, 'user_id' => self::USER_ID]);
        $this->inject($controller, 'authenticatedUserId', 1);

        $this->assertConflict($this->callHandler($controller, 'removeMember'));
    }

    public function testMovingTheLastSuperAdminGroupToAnotherTemplateIsAConflict(): void
    {
        $groups = $this->createMock(GroupService::class);
        $groups->method('updateGroup')->with(self::GROUP_ID, null, null, self::TEMPLATE_ID)
            ->willReturn($this->refusalFor(UberuserChange::groupTemplateChanged(self::GROUP_ID, self::TEMPLATE_ID)));

        $controller = $this->bareController(GroupsController::class);
        $this->injectBaseCollaborators($controller, 'PUT', ['perm_templ_id' => self::TEMPLATE_ID]);
        $this->inject($controller, 'groupService', $groups);
        $this->inject($controller, 'apiPermissionService', $this->allowAll());
        $this->inject($controller, 'pathParameters', ['id' => self::GROUP_ID]);
        $this->inject($controller, 'authenticatedUserId', 1);

        $this->assertConflict($this->callHandler($controller, 'updateGroup'));
    }

    public function testDroppingSuperAdminFromTheLastAdminTemplateIsAConflict(): void
    {
        $writes = $this->createMock(PermissionTemplateWriteService::class);
        $writes->method('update')
            ->willReturn($this->refusalFor(UberuserChange::templateDropsUberuser(self::TEMPLATE_ID)));

        $controller = $this->bareController(PermissionTemplatesController::class);
        $this->injectBaseCollaborators($controller, 'PUT', ['name' => 'Admins', 'descr' => 'No longer admins', 'permissions' => []]);
        $this->inject($controller, 'permissionTemplateRepository', $this->templates);
        $this->inject($controller, 'permissionTemplateWriteService', $writes);
        $this->inject($controller, 'apiPermissionService', $this->allowAll());
        $this->inject($controller, 'pathParameters', ['id' => (string)self::TEMPLATE_ID]);
        $this->inject($controller, 'authenticatedUserId', 1);

        $this->assertConflict($this->callHandler($controller, 'updatePermissionTemplate'));
    }

    /**
     * The refusal a real guard returns when $change removes the last super admin.
     *
     * @return array<string, mixed>
     */
    private function refusalFor(UberuserChange $change): array
    {
        $users = $this->createMock(UserPermissionReadInterface::class);
        $users->method('countUberusersAfter')->willReturnCallback(
            static fn(UberuserChange $after): int => $after == UberuserChange::none() ? 1 : 0
        );

        $refusal = (new LastSuperAdminGuard($users))->refusal($change);
        self::assertNotNull($refusal);

        return $refusal;
    }

    private function allowAll(): ApiPermissionService
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);
        $permissions->method('canManageGroups')->willReturn(true);

        return $permissions;
    }

    private function assertConflict(JsonResponse $response): void
    {
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(LastSuperAdminGuard::MESSAGE, $this->messageOf($response));
    }
}
