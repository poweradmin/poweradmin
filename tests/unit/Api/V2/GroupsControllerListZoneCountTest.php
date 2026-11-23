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
use Poweradmin\Application\Service\GroupService;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Service\ApiPermissionService;
use ReflectionMethod;
use ReflectionProperty;

/**
 * GET /v2/groups reports the same zone_count the detail endpoint would show to the caller,
 * not the raw total of the group's zones. An ueberuser keeps the raw total.
 */
class GroupsControllerListZoneCountTest extends TestCase
{
    public function testMemberCountsOnlyViewableZones(): void
    {
        $body = $this->invokeListGroups(false);

        $this->assertSame(1, $body['data']['groups'][0]['zone_count']);
    }

    public function testUeberuserKeepsTheRawTotal(): void
    {
        $body = $this->invokeListGroups(true);

        $this->assertSame(2, $body['data']['groups'][0]['zone_count']);
    }

    private function invokeListGroups(bool $isAdmin): array
    {
        $controller = new TestableGroupsController();

        $group = $this->createMock(UserGroup::class);
        $group->method('getId')->willReturn(5);
        $group->method('getName')->willReturn('Team');
        $group->method('getDescription')->willReturn('');
        $group->method('getPermTemplId')->willReturn(1);
        $group->method('getCreatedAt')->willReturn(null);

        $groupService = $this->createMock(GroupService::class);
        $groupService->method('listGroups')->willReturn([$group]);
        $groupService->method('getGroupDetails')->willReturn(['memberCount' => 2, 'zoneCount' => 2]);

        $zoneGroupService = $this->createMock(ZoneGroupService::class);
        $zoneGroupService->method('listGroupZones')->willReturn([$this->zone(10), $this->zone(11)]);

        $apiPermissionService = $this->createMock(ApiPermissionService::class);
        $apiPermissionService->method('userHasPermission')->willReturn($isAdmin);
        $apiPermissionService->method('canViewZone')
            ->willReturnCallback(fn(int $userId, int $zoneId) => $zoneId === 10);

        foreach (
            [
                'groupService' => $groupService,
                'zoneGroupService' => $zoneGroupService,
                'apiPermissionService' => $apiPermissionService,
                'authenticatedUserId' => 7,
            ] as $name => $value
        ) {
            (new ReflectionProperty(GroupsController::class, $name))->setValue($controller, $value);
        }

        $response = (new ReflectionMethod(GroupsController::class, 'listGroups'))->invoke($controller);

        return json_decode($response->getContent(), true);
    }

    private function zone(int $id): object
    {
        return new class ($id) {
            public function __construct(private int $id)
            {
            }

            public function getDomainId(): int
            {
                return $this->id;
            }
        };
    }
}
