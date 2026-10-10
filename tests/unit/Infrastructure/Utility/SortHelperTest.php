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

namespace Poweradmin\Tests\Unit\Infrastructure\Utility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\SortHelper;

#[CoversClass(SortHelper::class)]
class SortHelperTest extends TestCase
{
    public function testTheTableFormSortsItsNameColumn(): void
    {
        $this->assertSame(
            SortHelper::naturalSortOrder('domains.name', 'mysql', 'DESC'),
            SortHelper::getNaturalSortOrder('domains', 'mysql', 'DESC')
        );
    }

    public function testAnyColumnCanBeSortedNaturally(): void
    {
        $this->assertSame(
            'z.zone_name+0<>0 ASC, z.zone_name+0 ASC, z.zone_name ASC',
            SortHelper::naturalSortOrder('z.zone_name', 'sqlite')
        );
        $this->assertSame(
            "SUBSTRING(z.zone_name FROM '\\.arpa$') DESC, LENGTH(SUBSTRING(z.zone_name FROM '^[0-9]+')) DESC, z.zone_name DESC",
            SortHelper::naturalSortOrder('z.zone_name', 'pgsql', 'desc')
        );
    }

    public function testAnUnknownDirectionFallsBackToAscending(): void
    {
        $this->assertSame('z.zone_name ASC', SortHelper::naturalSortOrder('z.zone_name', 'other', 'sideways'));
    }
}
