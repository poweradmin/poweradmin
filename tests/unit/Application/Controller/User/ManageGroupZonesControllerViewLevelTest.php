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
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\User\ManageGroupZonesController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The group zones page consults the zone view gate before its two bulk zone
 * lookups: without a view level the owned list renders empty, the audit line
 * falls back to zone ids, and the refusal is flashed.
 */
#[CoversClass(ManageGroupZonesController::class)]
class ManageGroupZonesControllerViewLevelTest extends SeamControllerTestCase
{
    private const GROUP_ID = 3;

    private string $viewLevel = 'all';

    /** @var DomainRepositoryInterface&MockObject */
    private DomainRepositoryInterface $domains;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        $permissions->method('isAdmin')->willReturn(true);
        $permissions->method('getViewPermissionLevel')->willReturnCallback(fn(): string => $this->viewLevel);

        $groups = $this->createMock(UserGroupRepositoryInterface::class);
        $groups->method('findById')->willReturn(new UserGroup(self::GROUP_ID, 'Operators', null, 2));

        $zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $zoneGroups->method('findByGroupId')->willReturn([new ZoneGroup(1, 12, self::GROUP_ID)]);
        $zoneGroups->method('exists')->willReturn(false);

        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getZoneInfoFromIds')->willReturn([['id' => 12, 'name' => 'example.com', 'type' => 'MASTER']]);
        $this->domains->method('listZoneNames')->willReturn([]);

        $repositories = $this->createMock(RepositoryFactory::class);
        $repositories->method('createDomainRepository')->willReturn($this->domains);

        $this->audit = $this->createMock(AuditService::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('userGroupRepository')->willReturn($groups);
        $this->factory->method('zoneGroupRepository')->willReturn($zoneGroups);
        $this->factory->method('zoneGroupService')->willReturn(new ZoneGroupService($zoneGroups, $groups, $this->createMock(ZoneOwnershipGuard::class)));
        $this->factory->method('repositoryFactory')->willReturn($repositories);
        $this->factory->method('auditService')->willReturn($this->audit);
    }

    private function makeController(): TestableManageGroupZonesController
    {
        return new TestableManageGroupZonesController(['id' => (string)self::GROUP_ID] + $this->requestData(), $this->environment($this->configure()));
    }

    public function testThePageListsTheOwnedZonesByName(): void
    {
        $controller = $this->makeController();
        $controller->run();

        $this->assertSame('manage_group_zones.html', $controller->rendered[0][0]);
        $this->assertSame([['id' => 12, 'name' => 'example.com', 'type' => 'MASTER']], $controller->rendered[0][1]['owned_zones']);
        $this->assertSame([], $this->messagesFor('system'));
    }

    public function testWithoutAnyViewLevelTheOwnedListIsEmptyAndTheRefusalIsFlashed(): void
    {
        $this->viewLevel = 'none';
        $this->domains->expects($this->never())->method('getZoneInfoFromIds');

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([], $controller->rendered[0][1]['owned_zones']);
        $this->assertSame([['error', 'You do not have permission to view this zone.']], $this->messagesFor('system'));
    }

    public function testWithoutAnyViewLevelAnAddedZoneIsAuditedByIdAndTheRefusalIsFlashed(): void
    {
        $this->viewLevel = 'none';
        $this->domains->expects($this->never())->method('getZoneInfoFromIds');
        $this->audit->expects($this->once())->method('logGroupZonesAdd')->with(self::GROUP_ID, 'Operators', ['ID:12']);
        $this->post(['action' => 'add', 'domain_ids' => '12']);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['success', '1 zone added to group.']], $this->messagesFor('manage_group_zones'));
        $this->assertSame([['error', 'You do not have permission to view this zone.']], $this->messagesFor('system'));
    }
}
