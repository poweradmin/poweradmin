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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipRefusal;

/**
 * Group grants on a zone id two zones share are not honoured, so they must not keep
 * the zone's last real owner removable, nor stop the inert grant being cleaned up.
 */
class ZoneOwnershipGuardSharedZoneIdTest extends TestCase
{
    private function guard(bool $shared): ZoneOwnershipGuard
    {
        $zones = $this->createStub(ZoneOwnershipRepositoryInterface::class);
        $zones->method('isSharedZoneId')->willReturn($shared);
        $zones->method('getZoneOwners')->willReturn([['id' => 1, 'username' => 'creator', 'fullname' => '']]);

        $groups = $this->createStub(ZoneGroupRepositoryInterface::class);
        $groups->method('findByDomainId')->willReturn([ZoneGroup::create(5, 7)]);

        $config = $this->createStub(ConfigurationInterface::class);
        $config->method('get')->willReturn(ZoneOwnershipModeService::MODE_BOTH);

        return new ZoneOwnershipGuard($zones, $groups, new ZoneOwnershipModeService($config), $this->createStub(TransactionInterface::class));
    }

    public function testTheLastRealOwnerOfASharedZoneIdStays(): void
    {
        $refusal = $this->guard(true)->refuseUserOwnerRemoval(5, 1);

        $this->assertInstanceOf(ZoneOwnershipRefusal::class, $refusal);
        $this->assertSame(ZoneOwnershipRefusal::LAST_OWNER, $refusal->code);
        $this->assertNull($this->guard(false)->refuseUserOwnerRemoval(5, 1), 'A real group keeps an unshared zone owned');
    }

    public function testAnInertGroupGrantCanAlwaysBeRemoved(): void
    {
        $this->assertNull($this->guard(true)->refuseGroupRemoval(5, 7));
        $this->assertTrue($this->guard(true)->refusesNewGrants(5));
        $this->assertFalse($this->guard(false)->refusesNewGrants(5));
    }
}
