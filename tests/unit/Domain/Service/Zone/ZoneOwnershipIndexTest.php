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
use Poweradmin\Domain\Service\Zone\ZoneOwnershipIndex;

class ZoneOwnershipIndexTest extends TestCase
{
    public function testAllLevelGrantsWithoutOwnership(): void
    {
        $this->assertTrue((new ZoneOwnershipIndex(7, [], [], []))->allows('all', 42));
    }

    public function testNoneLevelDeniesEvenAnOwner(): void
    {
        $this->assertFalse((new ZoneOwnershipIndex(7, [1], [42 => [7]], [42 => [1]]))->allows('none', 42));
    }

    public function testOwnLevelAllowsDirectOwner(): void
    {
        $this->assertTrue((new ZoneOwnershipIndex(7, [], [42 => [7]], []))->allows('own', 42));
    }

    public function testOwnLevelAllowsOwnerViaAnyGroup(): void
    {
        $this->assertTrue((new ZoneOwnershipIndex(7, [3, 5], [42 => [99]], [42 => [5]]))->allows('own', 42));
    }

    public function testOwnLevelDeniesNonOwner(): void
    {
        $index = new ZoneOwnershipIndex(7, [1, 2], [42 => [99]], [42 => [9]]);

        $this->assertFalse($index->owns(42));
        $this->assertFalse($index->allows('own', 42));
    }

    public function testOwnAsClientCountsLikeOwn(): void
    {
        $this->assertTrue((new ZoneOwnershipIndex(7, [], [42 => [7]], []))->allows('own_as_client', 42));
    }
}
