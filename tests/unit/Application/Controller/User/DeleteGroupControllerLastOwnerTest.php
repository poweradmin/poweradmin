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
use Poweradmin\Application\Controller\User\DeleteGroupController;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Backend\RepositoryFactory;
use Poweradmin\Application\Service\Zone\ZoneGroupService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The confirmed delete-group POST keeps the last-owner rule: a group that is
 * the only owner of a zone stays, with the refusal flashed onto the
 * confirmation page; otherwise the deletion is audited and confirmed.
 */
#[CoversClass(DeleteGroupController::class)]
class DeleteGroupControllerLastOwnerTest extends SeamControllerTestCase
{
    private const GROUP_ID = 3;
    private const ZONE_ID = 12;

    /** @var UserGroupRepositoryInterface&MockObject */
    private UserGroupRepositoryInterface $groups;

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
        $permissions->method('isAdmin')->willReturn(true);

        $this->groups = $this->createMock(UserGroupRepositoryInterface::class);
        $this->groups->method('findById')->with(self::GROUP_ID)
            ->willReturn(new UserGroup(self::GROUP_ID, 'Operators', null, 2));
        $this->groups->method('countMembers')->willReturn(1);
        $this->groups->method('countZones')->willReturn(1);

        $this->zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $this->zones = $this->createMock(ZoneOwnershipRepositoryInterface::class);
        $this->audit = $this->createMock(AuditService::class);

        $config = $this->configure(['permissions' => ['show_group_access_templates' => true]]);
        $guard = new ZoneOwnershipGuard($this->zones, $this->zoneGroups, new ZoneOwnershipModeService($config));

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('orphan.example.com');
        $repositories = $this->createMock(RepositoryFactory::class);
        $repositories->method('createDomainRepository')->willReturn($domains);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('userGroupRepository')->willReturn($this->groups);
        $this->factory->method('zoneOwnershipGuard')->willReturn($guard);
        $this->factory->method('zoneGroupService')->willReturn(new ZoneGroupService($this->zoneGroups, $this->groups, $guard));
        $this->factory->method('repositoryFactory')->willReturn($repositories);
        $this->factory->method('auditService')->willReturn($this->audit);
    }

    public function testTheGroupIsDeletedAuditedAndConfirmedWhenItsZoneKeepsAUserOwner(): void
    {
        $this->givenSoleGroupOfAZoneOwnedBy([5]);
        $this->groups->expects($this->once())->method('delete')->with(self::GROUP_ID)->willReturn(true);
        $this->audit->expects($this->once())->method('logGroupDelete');

        $halt = $this->runDeletion();

        $this->assertInstanceOf(RequestHalted::class, $halt);
        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/groups', $halt->target);
        $this->assertSame([['success', 'Group has been deleted successfully.']], $this->messagesFor('list_groups'));
    }

    public function testTheLastOwningGroupStaysAndTheRefusalNamesTheZone(): void
    {
        $this->givenSoleGroupOfAZoneOwnedBy([]);
        $this->groups->expects($this->never())->method('delete');
        $this->audit->expects($this->never())->method('logGroupDelete');

        $this->assertNull($this->runDeletion());

        $this->assertSame('delete_group.html', $this->renderedTemplate());
        $this->assertSame(
            [['error', 'Cannot delete this group: it is the last owner of orphan.example.com. Add another owner to those zones first.']],
            $this->messagesFor('delete_group')
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

    private function runDeletion(): ?RequestHalted
    {
        $this->post(['id' => (string)self::GROUP_ID, 'confirm' => 'yes']);
        $controller = new DeleteGroupController($this->requestData(), true, $this->environment($this->config));

        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        return null;
    }
}
