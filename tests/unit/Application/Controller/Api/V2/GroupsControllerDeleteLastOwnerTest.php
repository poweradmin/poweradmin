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
use Poweradmin\Application\Controller\Api\V2\GroupsController;
use Poweradmin\Application\Service\User\GroupService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TestHelpers\FakeConfiguration;

/**
 * DELETE /groups/{id} refuses with 409 when the group is the last owner of a
 * zone, even with confirm=true; the older confirm prompt keeps its wording.
 */
class GroupsControllerDeleteLastOwnerTest extends V2ControllerTestCase
{
    private const GROUP_ID = 3;
    private const ZONE_ID = 12;

    /** @var UserGroupRepositoryInterface&MockObject */
    private UserGroupRepositoryInterface $groups;

    /** @var ZoneGroupRepositoryInterface&MockObject */
    private ZoneGroupRepositoryInterface $zoneGroups;

    /** @var ZoneOwnershipRepositoryInterface&MockObject */
    private ZoneOwnershipRepositoryInterface $zones;

    protected function setUp(): void
    {
        parent::setUp();

        $this->groups = $this->createMock(UserGroupRepositoryInterface::class);
        $this->groups->method('findById')->with(self::GROUP_ID)
            ->willReturn(new UserGroup(self::GROUP_ID, 'Operators', null, 2));
        $this->groups->method('countMembers')->willReturn(1);
        $this->groups->method('countZones')->willReturn(1);
        $this->zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $this->zones = $this->createMock(ZoneOwnershipRepositoryInterface::class);
    }

    public function testTheLastOwningGroupIsRefusedWithTheZoneNamed(): void
    {
        $this->givenSoleGroupOfAZoneOwnedBy([]);
        $this->groups->expects($this->never())->method('delete');

        $response = $this->delete(['confirm' => 'true']);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            'Cannot delete this group: it is the last owner of orphan.example.com. Add another owner to those zones first.',
            $this->messageOf($response)
        );
    }

    public function testAGroupWhoseZonesKeepAUserOwnerIsDeleted(): void
    {
        $this->givenSoleGroupOfAZoneOwnedBy([5]);
        $this->groups->expects($this->once())->method('delete')->with(self::GROUP_ID)->willReturn(true);

        $response = $this->delete(['confirm' => 'true']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Group deleted successfully', $this->messageOf($response));
    }

    public function testTheConfirmPromptKeepsItsWording(): void
    {
        $this->givenSoleGroupOfAZoneOwnedBy([5]);
        $this->groups->expects($this->never())->method('delete');

        $response = $this->delete([]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            'Group still owns 1 zone(s). Deleting it leaves them without an owner. Repeat with confirm=true to proceed.',
            $this->messageOf($response)
        );
    }

    /**
     * @param list<int> $owners
     */
    private function givenSoleGroupOfAZoneOwnedBy(array $owners): void
    {
        $this->zoneGroups->method('findByGroupId')->with(self::GROUP_ID)
            ->willReturn([new ZoneGroup(null, self::ZONE_ID, self::GROUP_ID, null, 'orphan.example.com')]);
        $this->zoneGroups->method('findByDomainId')->with(self::ZONE_ID)
            ->willReturn([ZoneGroup::create(self::ZONE_ID, self::GROUP_ID)]);
        $this->zones->method('getZoneOwners')->with(self::ZONE_ID)->willReturn(
            array_map(static fn(int $id): array => ['id' => $id, 'fullname' => 'User ' . $id], $owners)
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function delete(array $query): JsonResponse
    {
        $controller = $this->bareController(GroupsController::class);
        $this->injectBaseCollaborators($controller, 'DELETE');
        // Request::create() files a DELETE's parameters under request, so the
        // confirm flag has to travel in the URI to reach the query bag
        $uri = '/api/v2/groups/' . self::GROUP_ID . ($query === [] ? '' : '?' . http_build_query($query));
        $this->inject($controller, 'request', Request::create($uri, 'DELETE'));

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canManageGroups')->willReturn(true);
        $this->inject($controller, 'apiPermissionService', $permissions);

        $guard = new ZoneOwnershipGuard(
            $this->zones,
            $this->zoneGroups,
            new ZoneOwnershipModeService(new FakeConfiguration())
        );
        $this->inject($controller, 'groupService', new GroupService($this->groups, $guard));
        $this->inject($controller, 'pathParameters', ['id' => self::GROUP_ID]);
        $this->inject($controller, 'authenticatedUserId', 1);

        return $this->callHandler($controller, 'deleteGroup');
    }
}
