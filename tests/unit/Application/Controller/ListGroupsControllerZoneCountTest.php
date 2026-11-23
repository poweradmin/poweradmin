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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\ListGroupsController;
use Poweradmin\Application\Service\GroupService;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The web group list shows a member the zones they may view, not the raw total of the
 * group's zones. Viewers of all zones and administrators keep the raw total.
 */
class ListGroupsControllerZoneCountTest extends TestCase
{
    public function testOwnViewCountsOnlyOwnedZones(): void
    {
        $this->assertSame(1, $this->shownZoneCount('own', false));
    }

    public function testNoViewPermissionCountsNothing(): void
    {
        $this->assertSame(0, $this->shownZoneCount('none', false));
    }

    public function testViewAllKeepsTheRawTotal(): void
    {
        $this->assertSame(2, $this->shownZoneCount('all', false));
    }

    public function testAdministratorKeepsTheRawTotal(): void
    {
        $this->assertSame(2, $this->shownZoneCount('all', true));
    }

    private function shownZoneCount(string $viewLevel, bool $isAdmin): int
    {
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn($isAdmin);
        $permissionService->method('getViewPermissionLevel')->willReturn($viewLevel);
        $permissionService->method('userOwnsZone')->willReturnCallback(fn(int $userId, int $zoneId) => $zoneId === 10);

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn(false);

        $controller = new class ($permissionService, $userContext, $config) extends ListGroupsController {
            public array $rendered = [];

            public function __construct(
                private PermissionService $permissions,
                private UserContextService $userContext,
                ConfigurationManager $config
            ) {
                $this->config = $config;
            }

            public function render(string $template, array $params): void
            {
                $this->rendered = $params;
            }

            protected function createPermissionService(): PermissionService
            {
                return $this->permissions;
            }

            protected function getUserContextService(): UserContextService
            {
                return $this->userContext;
            }

            protected function hasPermission(string $permission): bool
            {
                return false;
            }
        };

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

        (new ReflectionProperty(ListGroupsController::class, 'groupService'))->setValue($controller, $groupService);
        (new ReflectionProperty(ListGroupsController::class, 'zoneGroupService'))->setValue($controller, $zoneGroupService);

        (new ReflectionMethod(ListGroupsController::class, 'showGroupsList'))->invoke($controller);

        return $controller->rendered['groups'][0]['zone_count'];
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
