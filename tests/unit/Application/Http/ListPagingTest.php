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

namespace Poweradmin\Tests\Unit\Application\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\ListPaging;

#[CoversClass(ListPaging::class)]
class ListPagingTest extends TestCase
{
    public static function parameterCases(): array
    {
        return [
            'no per_page' => [1, 0, [1, 0]],
            'per_page zero' => [3, '0', [1, 0]],
            'negative per_page' => [1, -5, [1, 0]],
            'non-numeric per_page' => [1, 'abc', [1, 0]],
            'first page' => ['1', '50', [1, 50]],
            'page below 1' => ['-2', '50', [1, 50]],
            'non-numeric page' => ['x', '50', [1, 50]],
            'capped per_page' => [2, 20000, [2, 10000]],
        ];
    }

    #[DataProvider('parameterCases')]
    public function testParameters(mixed $page, mixed $perPage, array $expected): void
    {
        $this->assertSame($expected, ListPaging::parameters($page, $perPage, 10000));
    }

    public function testWithoutPerPageEverythingIsReturnedWithoutPagination(): void
    {
        [$items, $extra] = ListPaging::paginate([2 => 'a', 5 => 'b'], 1, 0);

        $this->assertSame(['a', 'b'], $items);
        $this->assertSame([], $extra);
    }

    public function testPagesAndReportsTotals(): void
    {
        $items = range(1, 7);

        [$page2, $extra] = ListPaging::paginate($items, 2, 3);
        $this->assertSame([4, 5, 6], $page2);
        $this->assertSame(['pagination' => ['current_page' => 2, 'per_page' => 3, 'total' => 7, 'last_page' => 3]], $extra);

        [$last] = ListPaging::paginate($items, 3, 3);
        $this->assertSame([7], $last);

        [$beyond, $beyondExtra] = ListPaging::paginate($items, 9, 3);
        $this->assertSame([], $beyond);
        $this->assertSame(7, $beyondExtra['pagination']['total']);
    }

    public function testEmptyListHasOneLastPage(): void
    {
        [$items, $extra] = ListPaging::paginate([], 1, 10);

        $this->assertSame([], $items);
        $this->assertSame(1, $extra['pagination']['last_page']);
        $this->assertSame(0, $extra['pagination']['total']);
    }

    public function testFilterContainsIgnoresCaseAndKeepsEverythingForAnEmptyNeedle(): void
    {
        $items = [
            ['name' => 'WWW.example.com', 'values' => ['192.0.2.1']],
            ['name' => 'mail.example.com', 'values' => ['192.0.2.2', '2001:db8::1']],
            ['name' => 'ftp.example.com', 'values' => [null]],
        ];
        $names = static fn(array $i): array => [$i['name']];
        $values = static fn(array $i): array => $i['values'];

        $this->assertSame([$items[0]], ListPaging::filterContains($items, 'www', $names));
        $this->assertSame([$items[1]], ListPaging::filterContains($items, 'DB8', $values));
        $this->assertSame($items, ListPaging::filterContains($items, '  ', $names));
        $this->assertSame([], ListPaging::filterContains($items, 'nope', $names));
    }

    public function testFilterContainsTreatsWildcardsLiterally(): void
    {
        $items = [['name' => 'a_b.example.com'], ['name' => 'ab.example.com']];

        $this->assertSame([$items[0]], ListPaging::filterContains($items, '_', static fn(array $i): array => [$i['name']]));
        $this->assertSame([], ListPaging::filterContains($items, '%', static fn(array $i): array => [$i['name']]));
    }

    public function testAHugePageIsAnEmptyPageInsteadOfAnOverflow(): void
    {
        [$page] = ListPaging::parameters('99999999999999999999', '50', 10000);

        [$items, $extra] = ListPaging::paginate(range(1, 7), $page, 50);

        $this->assertSame([], $items);
        $this->assertSame(7, $extra['pagination']['total']);
        $this->assertSame(1, $extra['pagination']['last_page']);
    }

    public function testIsPastEndAndExtraForSqlPagedLists(): void
    {
        $this->assertFalse(ListPaging::isPastEnd(1, 10, 0));
        $this->assertFalse(ListPaging::isPastEnd(3, 10, 25));
        $this->assertTrue(ListPaging::isPastEnd(4, 10, 25));
        $this->assertTrue(ListPaging::isPastEnd(PHP_INT_MAX, 10000, 25));

        $this->assertSame([], ListPaging::extra(1, 0, 25));
        $this->assertSame(
            ['pagination' => ['current_page' => 2, 'per_page' => 10, 'total' => 25, 'last_page' => 3]],
            ListPaging::extra(2, 10, 25)
        );
    }
}
