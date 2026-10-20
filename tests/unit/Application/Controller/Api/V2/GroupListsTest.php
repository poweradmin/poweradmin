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
use Poweradmin\Application\Controller\Api\V2\GroupZonesController;
use Poweradmin\Application\Service\User\GroupMembershipService;
use Poweradmin\Application\Service\User\GroupService;
use Poweradmin\Application\Service\Zone\ZoneGroupService;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\UserGroupMember;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Filtering, sorting and paging of GET /api/v2/groups and the member and zone
 * lists of a group, which run on the formatted list like the zone record list.
 */
#[CoversClass(GroupsController::class)]
#[CoversClass(GroupMembersController::class)]
#[CoversClass(GroupZonesController::class)]
class GroupListsTest extends V2ControllerTestCase
{
    private const COUNTS = [1 => [3, 10], 2 => [8, 2], 3 => [1, 10]];

    private function permissions(): ApiPermissionService
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);

        return $permissions;
    }

    /**
     * @param array<string, mixed> $query
     * @param int[]|null $detailsFor Group ids whose counts may be looked up, null for any
     */
    private function listGroups(array $query = [], ?array $detailsFor = null): JsonResponse
    {
        $groups = $this->createMock(GroupService::class);
        $groups->method('listGroups')->willReturn([
            new UserGroup(1, 'DNS ops', 'Operators', 2, null, '2026-01-03 10:00:00'),
            new UserGroup(2, 'admins', null, 1, null, '2026-01-01 10:00:00'),
            new UserGroup(3, 'Customers10', 'External ops team', 3, null, '2026-01-02 10:00:00'),
        ]);
        $groups->method('getGroupDetails')->willReturnCallback(function (int $id) use ($detailsFor): array {
            if ($detailsFor !== null && !in_array($id, $detailsFor, true)) {
                $this->fail("Counts of group $id were looked up although it is not on the page");
            }
            return ['memberCount' => self::COUNTS[$id][0], 'zoneCount' => self::COUNTS[$id][1]];
        });

        $controller = $this->bareController(GroupsController::class);
        $this->injectBaseCollaborators($controller, 'GET', null, $query);
        $this->inject($controller, 'apiPermissionService', $this->permissions());
        $this->inject($controller, 'groupService', $groups);
        $this->inject($controller, 'authenticatedUserId', 1);

        return $this->callHandler($controller, 'listGroups');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listMembers(array $query = []): JsonResponse
    {
        $membership = $this->createMock(GroupMembershipService::class);
        $membership->method('listGroupMembers')->willReturn([
            new UserGroupMember(1, 7, 4, '2026-02-03 10:00:00', 'dave', 'Dave Ops', 'dave@example.com'),
            new UserGroupMember(2, 7, 2, '2026-02-02 10:00:00', 'Bob', 'Bob Builder', 'bob@ops.example'),
            new UserGroupMember(3, 7, 3, '2026-02-01 10:00:00', 'carol', 'Carol', 'carol@example.com'),
        ]);

        $controller = $this->bareController(GroupMembersController::class);
        $this->injectBaseCollaborators($controller, 'GET', null, $query);
        $this->inject($controller, 'apiPermissionService', $this->permissions());
        $this->inject($controller, 'membershipService', $membership);
        $this->inject($controller, 'pathParameters', ['id' => '7']);
        $this->inject($controller, 'authenticatedUserId', 1);

        return $this->callHandler($controller, 'listMembers');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listZones(array $query = [], ?ApiKeyScope $scope = null): JsonResponse
    {
        $zoneGroups = $this->createMock(ZoneGroupService::class);
        $zoneGroups->method('listGroupZones')->willReturn([
            new ZoneGroup(1, 30, 7, '2026-03-03 10:00:00', 'zone10.example', 'MASTER'),
            new ZoneGroup(2, 20, 7, '2026-03-02 10:00:00', 'zone2.example', 'SLAVE'),
            new ZoneGroup(3, 10, 7, '2026-03-01 10:00:00', 'other.test', 'MASTER'),
        ]);

        $controller = $this->bareController(GroupZonesController::class);
        $this->injectBaseCollaborators($controller, 'GET', null, $query);
        $this->inject($controller, 'apiPermissionService', $this->permissions());
        $this->inject($controller, 'zoneGroupService', $zoneGroups);
        $this->inject($controller, 'pathParameters', ['id' => '7']);
        $this->inject($controller, 'authenticatedUserId', 1);
        if ($scope !== null) {
            $this->inject($controller, 'apiKeyScope', $scope);
        }

        return $this->callHandler($controller, 'listZones');
    }

    public function testTheGroupListIsUnchangedWithoutTheNewParameters(): void
    {
        $body = $this->decode($this->listGroups());

        $this->assertSame(['DNS ops', 'admins', 'Customers10'], array_column($body['data']['groups'], 'name'));
        $this->assertSame(
            ['id' => 1, 'name' => 'DNS ops', 'description' => 'Operators', 'perm_templ_id' => 2, 'max_zones' => null,
             'member_count' => 3, 'zone_count' => 10, 'created_at' => '2026-01-03 10:00:00'],
            $body['data']['groups'][0]
        );
        $this->assertArrayNotHasKey('pagination', $body);
    }

    public function testGroupsAreFilteredOnNameAndDescription(): void
    {
        $this->assertSame(['DNS ops', 'Customers10'], array_column($this->decode($this->listGroups(['q' => 'OPS']))['data']['groups'], 'name'));
    }

    public function testOnlyTheGroupsOnThePageAreCountedWhenTheSortNeedsNoCounts(): void
    {
        $body = $this->decode($this->listGroups(['sort' => 'name', 'per_page' => 1, 'page' => 2], [3]));

        $this->assertSame(['Customers10'], array_column($body['data']['groups'], 'name'));
        $this->assertSame(1, $body['data']['groups'][0]['member_count']);
        $this->assertSame(['current_page' => 2, 'per_page' => 1, 'total' => 3, 'last_page' => 3], $body['pagination']);
    }

    public function testASortOnTheCountsCountsEveryGroup(): void
    {
        $body = $this->decode($this->listGroups(['sort' => 'zone_count:desc,member_count', 'per_page' => 2]));

        $this->assertSame(['Customers10', 'DNS ops'], array_column($body['data']['groups'], 'name'));
    }

    public function testAnUnknownGroupSortFieldIs400(): void
    {
        $response = $this->listGroups(['sort' => 'perm_templ_id']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("Invalid sort field 'perm_templ_id'. Allowed fields: id, name, member_count, zone_count, created_at", $this->messageOf($response));
    }

    public function testMembersAreFilteredSortedAndPaged(): void
    {
        $this->assertSame(['dave', 'Bob', 'carol'], array_column($this->decode($this->listMembers())['data']['members'], 'username'));
        $this->assertSame(['dave', 'Bob'], array_column($this->decode($this->listMembers(['q' => 'OPS']))['data']['members'], 'username'));

        $body = $this->decode($this->listMembers(['sort' => 'username', 'per_page' => 2, 'page' => 2]));
        $this->assertSame(['dave'], array_column($body['data']['members'], 'username'));
        $this->assertSame(3, $body['pagination']['total']);
        $this->assertSame(400, $this->listMembers(['sort' => 'password'])->getStatusCode());
    }

    public function testZonesAreFilteredSortedNaturallyAndPaged(): void
    {
        $this->assertSame(['zone10.example', 'zone2.example'], array_column($this->decode($this->listZones(['q' => 'EXAMPLE']))['data']['zones'], 'zone_name'));

        $body = $this->decode($this->listZones(['sort' => 'zone_name', 'per_page' => 2]));
        $this->assertSame(['other.test', 'zone2.example'], array_column($body['data']['zones'], 'zone_name'));
        $this->assertSame(3, $body['pagination']['total']);
        $this->assertSame(400, $this->listZones(['sort' => 'owner'])->getStatusCode());
    }

    public function testTheZoneTotalLeavesOutZonesOutsideTheKeysScope(): void
    {
        $body = $this->decode($this->listZones(['per_page' => 10], new ApiKeyScope([10, 20], null, false)));

        $this->assertSame(['zone2.example', 'other.test'], array_column($body['data']['zones'], 'zone_name'));
        $this->assertSame(2, $body['pagination']['total']);
    }
}
