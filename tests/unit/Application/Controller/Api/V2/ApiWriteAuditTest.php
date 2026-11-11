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

use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V2\GroupMembersController;
use Poweradmin\Application\Controller\Api\V2\GroupsController;
use Poweradmin\Application\Controller\Api\V2\GroupZonesController;
use Poweradmin\Application\Controller\Api\V2\PermissionTemplatesController;
use Poweradmin\Application\Controller\Api\V2\UsersController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\User\GroupMembershipService;
use Poweradmin\Application\Service\User\GroupService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Zone\ZoneGroupService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Application\Service\User\PermissionTemplateWriteService;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The /api/v2 writes the web UI audits leave the same audit line, and only
 * once the write went through.
 */
class ApiWriteAuditTest extends V2ControllerTestCase
{
    private const CALLER = 1;
    private const GROUP_ID = 3;
    private const USER_ID = 7;
    private const ZONE_ID = 12;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    /** @var PermissionTemplateRepositoryInterface&MockObject */
    private PermissionTemplateRepositoryInterface $templates;

    /** @var UserGroupRepositoryInterface&MockObject */
    private UserGroupRepositoryInterface $groupRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit = $this->createMock(AuditService::class);
        $this->templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $this->templates->method('validateTemplateType')->willReturn(true);
        $this->templates->method('getMinimalPermissionTemplateId')->willReturn(2);
        $this->templates->method('getPermissionTemplateDetails')->willReturn(['id' => 2, 'name' => 'Operators', 'template_type' => 'group']);
        $this->groupRepository = $this->createMock(UserGroupRepositoryInterface::class);
        $this->groupRepository->method('findById')->willReturn($this->group());
    }

    protected function stubServiceFactory(): ControllerServiceFactory
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, $default = null) => $default);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->audit);
        $factory->method('userRepository')->willReturn($this->stubUsers());
        $factory->method('permissionTemplateRepository')->willReturn($this->templates);
        $factory->method('userGroupRepository')->willReturn($this->groupRepository);
        $factory->method('zoneOwnershipModeService')->willReturn(new ZoneOwnershipModeService($config));
        $factory->method('zoneOwnershipLimit')->willReturn($this->createMock(\Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit::class));
        $factory->method('permissionService')->willReturn($this->createMock(PermissionService::class));

        return $factory;
    }

    private function group(int $permTempl = 2, ?string $name = 'ops'): UserGroup
    {
        return new UserGroup(self::GROUP_ID, (string)$name, null, $permTempl);
    }

    private function allowAll(): ApiPermissionService
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);
        $permissions->method('canManageGroups')->willReturn(true);
        $permissions->method('canCreateUser')->willReturn(true);
        $permissions->method('canEditUser')->willReturn(true);
        $permissions->method('canDeleteUser')->willReturn(true);
        $permissions->method('canEditPermissionTemplates')->willReturn(true);
        $permissions->method('permissions')->willReturn($this->createMock(PermissionService::class));

        return $permissions;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $path
     */
    private function call(string $class, string $handler, string $method, ?array $body, array $properties, array $path = []): JsonResponse
    {
        $controller = $this->bareController($class);
        $this->injectBaseCollaborators($controller, $method, $body);
        $this->inject($controller, 'apiPermissionService', $this->allowAll());
        $this->inject($controller, 'pathParameters', $path);
        $this->inject($controller, 'authenticatedUserId', self::CALLER);
        foreach ($properties as $name => $value) {
            $this->inject($controller, $name, $value);
        }

        return $this->callHandler($controller, $handler);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $row
     */
    private function callUsers(string $handler, string $method, array $body, UserManagementService $users, array $row = []): JsonResponse
    {
        $lookup = $this->createMock(UserLookupInterface::class);
        $lookup->method('getUserById')->willReturn($row + ['id' => self::USER_ID, 'username' => 'carol', 'email' => 'carol@example.com', 'perm_templ' => 2, 'use_ldap' => 0, 'auth_method' => 'sql']);

        return $this->call(
            UsersController::class,
            $handler,
            $method,
            $body,
            ['userManagementService' => $users, 'userRepository' => $lookup],
            ['id' => self::USER_ID]
        );
    }

    private function users(bool $success): UserManagementService
    {
        $result = $success
            ? ['success' => true, 'user_id' => self::USER_ID, 'message' => 'ok', 'zones_affected' => 0]
            : ['success' => false, 'message' => 'refused', 'refusal' => Refusal::CONFLICT];
        $users = $this->createMock(UserManagementService::class);
        $users->method('createUser')->willReturn($result);
        $users->method('updateUser')->willReturn($result);
        $users->method('deleteUser')->willReturn($result);

        return $users;
    }

    public function testCreatingAUserIsAudited(): void
    {
        $this->audit->expects($this->once())->method('logUserAdd')->with('carol', 'carol@example.com');

        $response = $this->callUsers('createUser', 'POST', ['username' => 'carol', 'password' => 'Secret-pass-123', 'email' => 'carol@example.com', 'perm_templ' => 2], $this->users(true));

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testARefusedUserCreateIsNotAudited(): void
    {
        $this->audit->expects($this->never())->method('logUserAdd');

        $response = $this->callUsers('createUser', 'POST', ['username' => 'carol', 'password' => 'Secret-pass-123', 'email' => 'carol@example.com', 'perm_templ' => 2], $this->users(false));

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testUpdatingAUserIsAudited(): void
    {
        $this->audit->expects($this->once())->method('logUserEdit')->with('carol', 2, 'sql');
        $this->audit->expects($this->never())->method('logPermTemplateChange');

        $response = $this->callUsers('updateUser', 'PUT', ['fullname' => 'Carol'], $this->users(true));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testARefusedUserUpdateIsNotAudited(): void
    {
        $this->audit->expects($this->never())->method('logUserEdit');

        $response = $this->callUsers('updateUser', 'PUT', ['fullname' => 'Carol'], $this->users(false));

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testDeletingAUserIsAuditedUnderTheStoredUsername(): void
    {
        $this->audit->expects($this->once())->method('logUserDelete')->with('carol');

        $response = $this->callUsers('deleteUser', 'DELETE', [], $this->users(true));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testARefusedUserDeleteIsNotAudited(): void
    {
        $this->audit->expects($this->never())->method('logUserDelete');

        $response = $this->callUsers('deleteUser', 'DELETE', [], $this->users(false));

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testAssigningAPermissionTemplateIsAudited(): void
    {
        $this->audit->expects($this->once())->method('logPermTemplateChange')->with('carol', 2, 5);

        $response = $this->callUsers('assignPermissionTemplate', 'PUT', ['perm_templ' => 5], $this->assignUsers(true));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testARefusedPermissionTemplateAssignmentIsNotAudited(): void
    {
        $this->audit->expects($this->never())->method('logPermTemplateChange');

        $response = $this->callUsers('assignPermissionTemplate', 'PUT', ['perm_templ' => 5], $this->assignUsers(false));

        $this->assertSame(409, $response->getStatusCode());
    }

    private function assignUsers(bool $success): UserManagementService
    {
        $users = $this->createMock(UserManagementService::class);
        $users->method('assignPermissionTemplate')->willReturn(
            $success ? ['success' => true, 'message' => 'ok'] : ['success' => false, 'message' => 'refused', 'refusal' => Refusal::CONFLICT]
        );

        return $users;
    }

    public function testCreatingAGroupIsAudited(): void
    {
        $groups = $this->createMock(GroupService::class);
        $groups->method('createGroup')->willReturn($this->group());
        $this->audit->expects($this->once())->method('logGroupCreate')->with(self::GROUP_ID, 'ops', 'Operators', 2);

        $response = $this->call(GroupsController::class, 'createGroup', 'POST', ['name' => 'ops', 'perm_templ_id' => 2], ['groupService' => $groups]);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testARefusedGroupCreateIsNotAudited(): void
    {
        $groups = $this->createMock(GroupService::class);
        $groups->method('createGroup')->willThrowException(new \InvalidArgumentException('A group with this name already exists'));
        $this->audit->expects($this->never())->method('logGroupCreate');

        $response = $this->call(GroupsController::class, 'createGroup', 'POST', ['name' => 'ops', 'perm_templ_id' => 2], ['groupService' => $groups]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdatingAGroupIsAuditedWithTheChanges(): void
    {
        $groups = $this->createMock(GroupService::class);
        $groups->method('getGroupDetails')->willReturn(['group' => $this->group(2, 'ops'), 'memberCount' => 0, 'zoneCount' => 0]);
        $groups->method('updateGroup')->willReturn($this->group(4, 'ops2'));
        $this->audit->expects($this->once())->method('logGroupEdit')->with(
            self::GROUP_ID,
            'ops2',
            ["name: 'ops' -> 'ops2'", 'permission template: ID 2 -> ID 4']
        );

        $response = $this->call(GroupsController::class, 'updateGroup', 'PUT', ['name' => 'ops2', 'perm_templ_id' => 4], ['groupService' => $groups], ['id' => self::GROUP_ID]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testARefusedGroupUpdateIsNotAudited(): void
    {
        $groups = $this->createMock(GroupService::class);
        $groups->method('getGroupDetails')->willReturn(['group' => $this->group(), 'memberCount' => 0, 'zoneCount' => 0]);
        $groups->method('updateGroup')->willReturn(['success' => false, 'message' => 'refused', 'refusal' => Refusal::CONFLICT]);
        $this->audit->expects($this->never())->method('logGroupEdit');

        $response = $this->call(GroupsController::class, 'updateGroup', 'PUT', ['perm_templ_id' => 4], ['groupService' => $groups], ['id' => self::GROUP_ID]);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testDeletingAGroupIsAuditedWithItsCounts(): void
    {
        $groups = $this->createMock(GroupService::class);
        $groups->method('getGroupDetails')->willReturn(['group' => $this->group(), 'memberCount' => 2, 'zoneCount' => 0]);
        $groups->method('deleteGroup')->willReturn(true);
        $this->audit->expects($this->once())->method('logGroupDelete')->with(self::GROUP_ID, 'ops', 2, 0);

        $response = $this->call(GroupsController::class, 'deleteGroup', 'DELETE', null, ['groupService' => $groups], ['id' => self::GROUP_ID]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testARefusedGroupDeleteIsNotAudited(): void
    {
        $groups = $this->createMock(GroupService::class);
        $groups->method('getGroupDetails')->willReturn(['group' => $this->group(), 'memberCount' => 2, 'zoneCount' => 0]);
        $groups->method('deleteGroup')->willReturn(['success' => false, 'message' => 'refused', 'refusal' => Refusal::CONFLICT]);
        $this->audit->expects($this->never())->method('logGroupDelete');

        $response = $this->call(GroupsController::class, 'deleteGroup', 'DELETE', null, ['groupService' => $groups], ['id' => self::GROUP_ID]);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testAddingAGroupMemberIsAudited(): void
    {
        $membership = $this->createMock(GroupMembershipService::class);
        $this->audit->expects($this->once())->method('logApiGroupMemberAdd')->with(self::GROUP_ID, 'ops', 'apiuser');

        $response = $this->call(GroupMembersController::class, 'addMember', 'POST', ['user_id' => self::USER_ID], ['membershipService' => $membership], ['id' => self::GROUP_ID]);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testARefusedMemberAddIsNotAudited(): void
    {
        $membership = $this->createMock(GroupMembershipService::class);
        $membership->method('addUserToGroup')->willThrowException(new \InvalidArgumentException('User is already a member of this group'));
        $this->audit->expects($this->never())->method('logApiGroupMemberAdd');

        $response = $this->call(GroupMembersController::class, 'addMember', 'POST', ['user_id' => self::USER_ID], ['membershipService' => $membership], ['id' => self::GROUP_ID]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testRemovingAGroupMemberIsAudited(): void
    {
        $membership = $this->createMock(GroupMembershipService::class);
        $membership->method('removeUserFromGroup')->willReturn(true);
        $this->audit->expects($this->once())->method('logGroupMembersRemove')->with(self::GROUP_ID, 'ops', ['apiuser']);

        $response = $this->call(GroupMembersController::class, 'removeMember', 'DELETE', null, ['membershipService' => $membership], ['id' => self::GROUP_ID, 'user_id' => self::USER_ID]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testARefusedMemberRemovalIsNotAudited(): void
    {
        $membership = $this->createMock(GroupMembershipService::class);
        $membership->method('removeUserFromGroup')->willReturn(['success' => false, 'message' => 'refused', 'refusal' => Refusal::CONFLICT]);
        $this->audit->expects($this->never())->method('logGroupMembersRemove');

        $response = $this->call(GroupMembersController::class, 'removeMember', 'DELETE', null, ['membershipService' => $membership], ['id' => self::GROUP_ID, 'user_id' => self::USER_ID]);

        $this->assertSame(409, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function callGroupZones(string $handler, string $method, ?array $body, ZoneGroupService $zoneGroups): JsonResponse
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->willReturn(true);
        $domains->method('getDomainNameById')->willReturn('example.com');

        return $this->call(
            GroupZonesController::class,
            $handler,
            $method,
            $body,
            ['zoneGroupService' => $zoneGroups, 'domainRepository' => $domains],
            ['id' => self::GROUP_ID, 'zone_id' => self::ZONE_ID]
        );
    }

    public function testAssigningAZoneToAGroupIsAudited(): void
    {
        $zoneGroups = $this->createMock(ZoneGroupService::class);
        $zoneGroups->method('addGroupToZone')->willReturn(new ZoneGroup(null, self::ZONE_ID, self::GROUP_ID));
        $this->audit->expects($this->once())->method('logGroupZonesAdd')->with(self::GROUP_ID, 'ops', ['example.com']);

        $response = $this->callGroupZones('assignZone', 'POST', ['zone_id' => self::ZONE_ID], $zoneGroups);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testARefusedZoneAssignmentIsNotAudited(): void
    {
        $zoneGroups = $this->createMock(ZoneGroupService::class);
        $zoneGroups->method('addGroupToZone')->willThrowException(new \InvalidArgumentException('Group already owns this zone'));
        $this->audit->expects($this->never())->method('logGroupZonesAdd');

        $response = $this->callGroupZones('assignZone', 'POST', ['zone_id' => self::ZONE_ID], $zoneGroups);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUnassigningAZoneFromAGroupIsAudited(): void
    {
        $zoneGroups = $this->createMock(ZoneGroupService::class);
        $zoneGroups->method('removeGroupFromZone')->willReturn(true);
        $this->audit->expects($this->once())->method('logGroupZonesRemove')->with(self::GROUP_ID, 'ops', ['example.com']);

        $response = $this->callGroupZones('unassignZone', 'DELETE', null, $zoneGroups);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnAbsentZoneAssignmentRemovalIsNotAudited(): void
    {
        $zoneGroups = $this->createMock(ZoneGroupService::class);
        $zoneGroups->method('removeGroupFromZone')->willReturn(false);
        $this->audit->expects($this->never())->method('logGroupZonesRemove');

        $response = $this->callGroupZones('unassignZone', 'DELETE', null, $zoneGroups);

        $this->assertSame(404, $response->getStatusCode());
    }

    private function templateWrites(bool $success): PermissionTemplateWriteService
    {
        $result = $success
            ? ['success' => true, 'message' => 'ok', 'id' => 9, 'status' => 201]
            : ['success' => false, 'message' => 'refused', 'status' => 409, 'refusal' => Refusal::CONFLICT];
        $writes = $this->createMock(PermissionTemplateWriteService::class);
        $writes->method('create')->willReturn($result);
        $writes->method('update')->willReturn($result);

        return $writes;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function callTemplates(string $handler, string $method, array $body, bool $success): JsonResponse
    {
        return $this->call(
            PermissionTemplatesController::class,
            $handler,
            $method,
            $body,
            ['permissionTemplateRepository' => $this->templates, 'permissionTemplateWriteService' => $this->templateWrites($success)],
            ['id' => '9']
        );
    }

    public function testCreatingAPermissionTemplateIsAudited(): void
    {
        $this->audit->expects($this->once())->method('logPermTemplateAdd')->with('Helpers');

        $response = $this->callTemplates('createPermissionTemplate', 'POST', ['name' => 'Helpers', 'descr' => 'd'], true);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testARefusedPermissionTemplateCreateIsNotAudited(): void
    {
        $this->audit->expects($this->never())->method('logPermTemplateAdd');

        $response = $this->callTemplates('createPermissionTemplate', 'POST', ['name' => 'Helpers', 'descr' => 'd'], false);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testUpdatingAPermissionTemplateIsAudited(): void
    {
        $this->audit->expects($this->once())->method('logPermTemplateEdit')->with(9, 'Helpers');

        $response = $this->callTemplates('updatePermissionTemplate', 'PUT', ['name' => 'Helpers', 'descr' => 'd'], true);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testARefusedPermissionTemplateUpdateIsNotAudited(): void
    {
        $this->audit->expects($this->never())->method('logPermTemplateEdit');

        $response = $this->callTemplates('updatePermissionTemplate', 'PUT', ['name' => 'Helpers', 'descr' => 'd'], false);

        $this->assertSame(409, $response->getStatusCode());
    }
}
