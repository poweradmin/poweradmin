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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ZoneListPermissionService;

class ZoneListPermissionServiceTest extends TestCase
{
    public function testGroupMembershipIsOnlyReadWhenAZoneIsGroupOwned(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->expects($this->once())->method('getOwnerIdsByZoneIds')->with([4, 7])->willReturn([4 => [9]]);
        $zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $zoneGroups->method('findGroupIdsByDomainIds')->willReturn([]);
        $userGroups = $this->createMock(UserGroupRepositoryInterface::class);
        $userGroups->expects($this->never())->method('getGroupIdsForUser');

        $index = (new ZoneListPermissionService($zones, $zoneGroups, $userGroups))->index(9, ['4', 7, 4, 0]);

        $this->assertTrue($index->owns(4));
        $this->assertFalse($index->owns(7));
    }

    public function testGroupOwnershipUsesTheUsersGroups(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getOwnerIdsByZoneIds')->willReturn([]);
        $zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $zoneGroups->method('findGroupIdsByDomainIds')->willReturn([4 => [1, 2], 7 => [3]]);
        $userGroups = $this->createMock(UserGroupRepositoryInterface::class);
        $userGroups->method('getGroupIdsForUser')->with(9)->willReturn([2]);

        $index = (new ZoneListPermissionService($zones, $zoneGroups, $userGroups))->index(9, [4, 7]);

        $this->assertTrue($index->owns(4));
        $this->assertFalse($index->owns(7));
        $this->assertTrue($index->allows('own', 4));
        $this->assertFalse($index->allows('own', 7));
        $this->assertTrue($index->allows('all', 7));
    }
}
