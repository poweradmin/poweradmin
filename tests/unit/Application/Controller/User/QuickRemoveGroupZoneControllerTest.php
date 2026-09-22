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
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\User\QuickRemoveGroupZoneController;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Zone\ZoneGroupService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The quick-remove POST on the edit-group page keeps the last-owner rule: a
 * group that is the zone's only owner stays, with the refusal flashed to the
 * edit-group page; otherwise the removal is audited and confirmed.
 */
#[CoversClass(QuickRemoveGroupZoneController::class)]
class QuickRemoveGroupZoneControllerTest extends SeamControllerTestCase
{
    private const GROUP_ID = 3;
    private const ZONE_ID = 12;

    /** @var ZoneGroupRepositoryInterface&MockObject */
    private ZoneGroupRepositoryInterface $zoneGroups;

    /** @var ZoneOwnershipRepositoryInterface&MockObject */
    private ZoneOwnershipRepositoryInterface $zones;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);

        $groups = $this->createMock(UserGroupRepositoryInterface::class);
        $groups->method('findById')->with(self::GROUP_ID)->willReturn(new UserGroup(self::GROUP_ID, 'Operators', null, 2));

        $this->zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $this->zones = $this->createMock(ZoneOwnershipRepositoryInterface::class);
        $this->audit = $this->createMock(AuditService::class);

        $config = $this->configure(['permissions' => ['show_group_access_templates' => true]]);
        $guard = new ZoneOwnershipGuard($this->zones, $this->zoneGroups, new ZoneOwnershipModeService($config));

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('zoneGroupService')->willReturn(new ZoneGroupService($this->zoneGroups, $groups, $guard));
        $this->factory->method('auditService')->willReturn($this->audit);
    }

    /**
     * @param list<int> $owners
     * @param list<int> $groups
     */
    private function givenOwnership(array $owners, array $groups): void
    {
        $this->zones->method('getZoneOwners')->with(self::ZONE_ID)->willReturn(
            array_map(static fn(int $id): array => ['id' => $id, 'fullname' => 'User ' . $id], $owners)
        );
        $this->zoneGroups->method('findByDomainId')->with(self::ZONE_ID)->willReturn(
            array_map(static fn(int $id): ZoneGroup => ZoneGroup::create(self::ZONE_ID, $id), $groups)
        );
    }

    private function runRemoval(): RequestHalted
    {
        $this->post(['group_id' => (string)self::GROUP_ID, 'zone_id' => (string)self::ZONE_ID]);
        $controller = new QuickRemoveGroupZoneController($this->requestData(), true, $this->environment($this->config));

        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected a redirect to the edit-group page.');
    }

    public function testTheZoneIsRemovedAuditedAndConfirmedWhenAUserOwnerRemains(): void
    {
        $this->givenOwnership([5], [self::GROUP_ID]);
        $this->zoneGroups->expects($this->once())->method('remove')->with(self::ZONE_ID, self::GROUP_ID)->willReturn(true);
        $this->audit->expects($this->once())->method('logZoneGroupRemove')->with(self::ZONE_ID, (string)self::ZONE_ID, self::GROUP_ID);

        $halt = $this->runRemoval();

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/groups/' . self::GROUP_ID . '/edit', $halt->target);
        $this->assertSame([['success', 'Zone removed from group successfully.']], $this->messagesFor('edit_group'));
    }

    public function testTheLastOwningGroupStaysAndTheRefusalIsFlashed(): void
    {
        $this->givenOwnership([], [self::GROUP_ID]);
        $this->zoneGroups->expects($this->never())->method('remove');
        $this->audit->expects($this->never())->method('logZoneGroupRemove');

        $halt = $this->runRemoval();

        $this->assertSame('/groups/' . self::GROUP_ID . '/edit', $halt->target);
        $this->assertSame(
            [['error', 'Cannot remove the last owner: this would leave the zone with no ownership. Add another owner or a group first.']],
            $this->messagesFor('edit_group')
        );
    }

    public function testAGroupThatDoesNotOwnTheZoneIsReportedAsAWarning(): void
    {
        $this->givenOwnership([], [4]);
        $this->zoneGroups->method('remove')->with(self::ZONE_ID, self::GROUP_ID)->willReturn(false);
        $this->audit->expects($this->never())->method('logZoneGroupRemove');

        $halt = $this->runRemoval();

        $this->assertSame('/groups/' . self::GROUP_ID . '/edit', $halt->target);
        $this->assertSame([['warning', 'Zone was not owned by this group.']], $this->messagesFor('edit_group'));
    }
}
