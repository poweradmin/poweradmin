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

    public function testOrderByMapsEachKeyToItsColumnAndAppendsTheTiebreaker(): void
    {
        $columns = [
            'name' => fn(string $direction): string => "natural(name) $direction",
            'type' => 't.type',
        ];
        $sort = [['field' => 'type', 'desc' => true], ['field' => 'name', 'desc' => false]];

        $this->assertSame('t.type DESC, natural(name) ASC, t.id', SortHelper::orderBy($sort, $columns, 't.id', 't.name'));
    }

    public function testOrderByWithoutKeysKeepsTheDefaultOrder(): void
    {
        $this->assertSame('t.name', SortHelper::orderBy([], ['type' => 't.type'], 't.id', 't.name'));
    }

    public function testAColumnNamedLikeAPhpFunctionIsNotCalled(): void
    {
        // Only closures are called, so a column such as "date" stays a column
        $this->assertSame('date ASC, id', SortHelper::orderBy([['field' => 'date', 'desc' => false]], ['date' => 'date'], 'id', 'id'));
    }

    public function testAnUnmappedFieldIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        SortHelper::orderBy([['field' => 'owner', 'desc' => false]], ['name' => 't.name'], 't.id', 't.name');
    }
}
