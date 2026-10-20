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

namespace Poweradmin\Tests\Unit\Application\Service\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\User\GroupService;
use Poweradmin\Domain\Error\LastZoneOwnerException;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use TestHelpers\FakeConfiguration;

/**
 * Deleting a group takes its zones_groups rows with it, so the last-owner rule
 * has to hold for the group's zones before the group itself may go.
 */
#[CoversClass(GroupService::class)]
#[CoversClass(ZoneOwnershipGuard::class)]
class GroupDeletionLastOwnerTest extends TestCase
{
    private const GROUP_ID = 3;
    private const ORPHANED_ZONE = 12;
    private const SHARED_ZONE = 13;

    /** @var UserGroupRepositoryInterface&MockObject */
    private UserGroupRepositoryInterface $groups;

    /** @var ZoneGroupRepositoryInterface&MockObject */
    private ZoneGroupRepositoryInterface $zoneGroups;

    /** @var ZoneOwnershipRepositoryInterface&MockObject */
    private ZoneOwnershipRepositoryInterface $zones;

    /** @var array<int, list<int>> */
    private array $zoneOwners = [];

    /** @var array<int, list<int>> */
    private array $zoneGroupIds = [];

    protected function setUp(): void
    {
        $this->groups = $this->createMock(UserGroupRepositoryInterface::class);
        $this->groups->method('findById')->with(self::GROUP_ID)
            ->willReturn(new UserGroup(self::GROUP_ID, 'Operators', null, 2));
        $this->zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $this->zones = $this->createMock(ZoneOwnershipRepositoryInterface::class);

        $this->zones->method('getZoneOwners')->willReturnCallback(fn(int $zoneId): array => array_map(
            static fn(int $id): array => ['id' => $id, 'fullname' => 'User ' . $id],
            $this->zoneOwners[$zoneId] ?? []
        ));
        $this->zoneGroups->method('findByDomainId')->willReturnCallback(fn(int $zoneId): array => array_map(
            static fn(int $id): ZoneGroup => ZoneGroup::create($zoneId, $id),
            $this->zoneGroupIds[$zoneId] ?? []
        ));
    }

    public function testTheGroupStaysWhenItIsTheOnlyOwnerOfAZone(): void
    {
        $this->givenGroupZones([self::ORPHANED_ZONE => 'orphan.example.com']);
        $this->givenZoneOwnership(self::ORPHANED_ZONE, [], [self::GROUP_ID]);
        $this->groups->expects($this->never())->method('delete');

        try {
            $this->service()->deleteGroup(self::GROUP_ID);
        } catch (LastZoneOwnerException $refusal) {
            $this->assertSame([self::ORPHANED_ZONE => 'orphan.example.com'], $refusal->getZones());
            $this->assertSame('orphan.example.com', $refusal->getZoneList());
            return;
        }

        $this->fail('Expected the deletion to be refused.');
    }

    public function testTheGroupGoesWhenEveryZoneKeepsAnotherOwner(): void
    {
        $this->givenGroupZones([self::SHARED_ZONE => 'shared.example.com']);
        $this->givenZoneOwnership(self::SHARED_ZONE, [5], [self::GROUP_ID]);
        $this->groups->expects($this->once())->method('delete')->with(self::GROUP_ID)->willReturn(true);

        $this->assertTrue($this->service()->deleteGroup(self::GROUP_ID));
    }

    public function testOnlyTheZonesThatWouldBeOrphanedAreNamed(): void
    {
        $this->givenGroupZones([
            self::ORPHANED_ZONE => 'orphan.example.com',
            self::SHARED_ZONE => 'shared.example.com',
        ]);
        $this->givenZoneOwnership(self::ORPHANED_ZONE, [], [self::GROUP_ID]);
        $this->givenZoneOwnership(self::SHARED_ZONE, [5], [self::GROUP_ID]);
        $this->groups->expects($this->never())->method('delete');

        $this->expectException(LastZoneOwnerException::class);

        try {
            $this->service()->deleteGroup(self::GROUP_ID);
        } catch (LastZoneOwnerException $refusal) {
            $this->assertSame('orphan.example.com', $refusal->getZoneList());
            throw $refusal;
        }
    }

    /**
     * @param array<int, string> $zones Zone id => zone name
     */
    private function givenGroupZones(array $zones): void
    {
        $rows = [];
        foreach ($zones as $domainId => $name) {
            $rows[] = new ZoneGroup(null, $domainId, self::GROUP_ID, null, $name);
        }
        $this->zoneGroups->method('findByGroupId')->with(self::GROUP_ID)->willReturn($rows);
    }

    /**
     * @param list<int> $owners
     * @param list<int> $groups
     */
    private function givenZoneOwnership(int $zoneId, array $owners, array $groups): void
    {
        $this->zoneOwners[$zoneId] = $owners;
        $this->zoneGroupIds[$zoneId] = $groups;
    }

    private function service(): GroupService
    {
        $guard = new ZoneOwnershipGuard(
            $this->zones,
            $this->zoneGroups,
            new ZoneOwnershipModeService(new FakeConfiguration()),
            $this->createMock(TransactionInterface::class)
        );

        return new GroupService($this->groups, $guard);
    }
}
