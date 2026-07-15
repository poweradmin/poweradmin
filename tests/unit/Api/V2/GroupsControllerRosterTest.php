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

namespace Poweradmin\Tests\Unit\Api\V2;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\GroupsController;
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Application\Service\GroupService;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\UserGroupMember;
use Poweradmin\Domain\Service\ApiPermissionService;
use ReflectionMethod;
use ReflectionProperty;

/**
 * GET /v2/groups/{id} must only disclose the member roster to ueberusers,
 * matching listMembers and the web UI. A plain group member gets group metadata
 * but never the list of co-members.
 */
class GroupsControllerRosterTest extends TestCase
{
    private const GROUP_ID = 5;
    private const CALLER_ID = 7;

    public function testNonUeberuserDoesNotSeeMemberRoster(): void
    {
        $body = $this->invokeGetGroup(isAdmin: false);

        $this->assertSame([], $body['data']['group']['members'], 'A non-ueberuser must not receive the member roster.');
        $this->assertSame(2, $body['data']['group']['member_count'], 'The aggregate member count is still returned.');
    }

    public function testUeberuserSeesMemberRoster(): void
    {
        $body = $this->invokeGetGroup(isAdmin: true);

        $members = $body['data']['group']['members'];
        $this->assertCount(2, $members);
        $this->assertSame(['alice', 'bob'], array_column($members, 'username'));
    }

    private function invokeGetGroup(bool $isAdmin): array
    {
        $controller = new TestableGroupsController();

        $group = $this->createMock(UserGroup::class);
        $group->method('getId')->willReturn(self::GROUP_ID);
        $group->method('getName')->willReturn('Team');
        $group->method('getDescription')->willReturn('');
        $group->method('getPermTemplId')->willReturn(1);
        $group->method('getCreatedAt')->willReturn(null);

        $groupService = $this->createMock(GroupService::class);
        $groupService->method('getGroupById')->willReturn($group);
        $groupService->method('getGroupDetails')->willReturn(['memberCount' => 2, 'zoneCount' => 0]);

        $membershipService = $this->createMock(GroupMembershipService::class);
        $membershipService->method('listGroupMembers')->willReturn([
            $this->member(11, 'alice'),
            $this->member(12, 'bob'),
        ]);

        $zoneGroupService = $this->createMock(ZoneGroupService::class);
        $zoneGroupService->method('listGroupZones')->willReturn([]);

        $apiPermissionService = $this->createMock(ApiPermissionService::class);
        $apiPermissionService->method('userHasPermission')
            ->with(self::CALLER_ID, 'user_is_ueberuser')
            ->willReturn($isAdmin);

        $this->setProperty($controller, 'groupService', $groupService);
        $this->setProperty($controller, 'membershipService', $membershipService);
        $this->setProperty($controller, 'zoneGroupService', $zoneGroupService);
        $this->setProperty($controller, 'apiPermissionService', $apiPermissionService);
        $this->setProperty($controller, 'authenticatedUserId', self::CALLER_ID);
        $this->setProperty($controller, 'pathParameters', ['id' => (string)self::GROUP_ID]);

        $method = new ReflectionMethod(GroupsController::class, 'getGroup');
        $method->setAccessible(true);
        $response = $method->invoke($controller);

        return json_decode($response->getContent(), true);
    }

    private function member(int $id, string $username): UserGroupMember
    {
        $member = $this->createMock(UserGroupMember::class);
        $member->method('getUserId')->willReturn($id);
        $member->method('getUsername')->willReturn($username);

        return $member;
    }

    private function setProperty(object $controller, string $name, mixed $value): void
    {
        $property = new ReflectionProperty(GroupsController::class, $name);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }
}
