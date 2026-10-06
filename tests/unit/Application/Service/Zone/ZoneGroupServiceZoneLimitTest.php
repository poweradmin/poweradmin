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

namespace Poweradmin\Tests\Unit\Application\Service\Zone;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Zone\ZoneGroupService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ZoneLimitBreach;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * Granting a zone to a group respects the group's zone limit (#72).
 */
class ZoneGroupServiceZoneLimitTest extends TestCase
{
    private const GROUP_ID = 3;

    /** @var ZoneGroupRepositoryInterface&MockObject */
    private ZoneGroupRepositoryInterface $zoneGroups;

    protected function setUp(): void
    {
        $this->zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $this->zoneGroups->method('exists')->willReturn(false);
    }

    public function testAGroupAtItsLimitGetsTheBreachAndNoGrant(): void
    {
        $breach = new ZoneLimitBreach(ZoneLimitBreach::SUBJECT_GROUP, 'ops', 2, 2);
        $this->zoneGroups->expects($this->never())->method('add');

        $this->assertSame($breach, $this->service(fn() => $breach)->addGroupToZone(7, self::GROUP_ID));
    }

    public function testAGroupWithinItsLimitIsGranted(): void
    {
        $grant = ZoneGroup::create(7, self::GROUP_ID);
        $this->zoneGroups->expects($this->once())->method('add')->with(7, self::GROUP_ID)->willReturn($grant);

        $this->assertSame($grant, $this->service(fn() => null)->addGroupToZone(7, self::GROUP_ID));
    }

    public function testNoLimitServiceEnforcesNothing(): void
    {
        $grant = ZoneGroup::create(7, self::GROUP_ID);
        $this->zoneGroups->expects($this->once())->method('add')->willReturn($grant);

        $this->assertSame($grant, $this->service(null)->addGroupToZone(7, self::GROUP_ID));
    }

    public function testBulkStopsAtTheLimitAndReportsTheRest(): void
    {
        $granted = 0;
        $breach = new ZoneLimitBreach(ZoneLimitBreach::SUBJECT_GROUP, 'ops', 1, 1);
        $this->zoneGroups->expects($this->once())->method('add')->with(10, self::GROUP_ID)
            ->willReturnCallback(function () use (&$granted) {
                $granted++;
                return ZoneGroup::create(10, self::GROUP_ID);
            });

        $results = $this->service(function () use (&$granted, $breach) {
            return $granted >= 1 ? $breach : null;
        })->bulkAddZones(self::GROUP_ID, [10, 11, 12]);

        $this->assertSame([10], $results['success']);
        $this->assertSame([11 => $breach->message(), 12 => $breach->message()], $results['failed']);
        $this->assertSame($breach, $results['limit']);
    }

    public function testBulkWithinTheLimitHasNoLimitKey(): void
    {
        $this->zoneGroups->method('add')->willReturnCallback(fn(int $zone, int $group) => ZoneGroup::create($zone, $group));

        $results = $this->service(fn() => null)->bulkAddZones(self::GROUP_ID, [10, 11]);

        $this->assertSame([10, 11], $results['success']);
        $this->assertArrayNotHasKey('limit', $results);
    }

    private function service(?callable $groupBreach): ZoneGroupService
    {
        $groups = $this->createMock(UserGroupLookupInterface::class);
        $groups->method('findById')->willReturn(new UserGroup(self::GROUP_ID, 'ops', null, 1));

        $zones = $this->createMock(ZoneOwnershipRepositoryInterface::class);
        $zones->method('isSharedZoneId')->willReturn(false);
        $guard = new ZoneOwnershipGuard(
            $zones,
            $this->zoneGroups,
            new ZoneOwnershipModeService($this->createMock(ConfigurationInterface::class)),
            $this->createMock(TransactionInterface::class)
        );

        $limit = null;
        if ($groupBreach !== null) {
            $limit = $this->createMock(ZoneOwnershipLimit::class);
            $limit->method('groupBreach')->with(self::GROUP_ID)->willReturnCallback($groupBreach);
        }

        return new ZoneGroupService($this->zoneGroups, $groups, $guard, $limit);
    }
}
