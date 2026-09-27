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
use Poweradmin\Application\Http\ListSort;

#[CoversClass(ListSort::class)]
class ListSortTest extends TestCase
{
    private const ALLOWED = ['name', 'type', 'ttl'];

    private const ROWS = [
        ['name' => 'www10', 'type' => 'A', 'ttl' => 300],
        ['name' => 'WWW2', 'type' => 'AAAA', 'ttl' => 3600],
        ['name' => 'mail', 'type' => 'A', 'ttl' => 300],
        ['name' => 'api', 'type' => 'AAAA', 'ttl' => 3600],
    ];

    public function testEmptyValueKeepsTheOrder(): void
    {
        foreach ([null, '', '  '] as $raw) {
            $sort = ListSort::fromQuery($raw, self::ALLOWED);
            $this->assertNull($sort->error);
            $this->assertSame(self::ROWS, $sort->sortRows(self::ROWS));
        }
    }

    public function testParsesFieldsAndDirectionsCaseInsensitively(): void
    {
        $sort = ListSort::fromQuery('type, TTL:DESC,name:asc', self::ALLOWED);

        $this->assertNull($sort->error);
        $this->assertSame(['mail', 'www10', 'api', 'WWW2'], array_column($sort->sortRows(self::ROWS), 'name'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidValues(): array
    {
        return [
            'unknown field' => ['content', "Invalid sort field 'content'. Allowed fields: name, type, ttl"],
            'sql injection attempt' => ['name;DROP TABLE users', "Invalid sort field 'name;drop table users'. Allowed fields: name, type, ttl"],
            'bad direction' => ['name:up', "Invalid sort direction 'up' for field 'name'. Use asc or desc"],
            'empty field' => ['name,', "Invalid sort field ''. Allowed fields: name, type, ttl"],
            'duplicate field' => ['name,name:desc', "Sort field 'name' is given more than once"],
            'too many fields' => ['name,type,ttl,name,type,ttl', 'At most 5 sort fields are allowed'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesCarryAnErrorInsteadOfThrowing(string $value, string $error): void
    {
        $sort = ListSort::fromQuery($value, self::ALLOWED);

        $this->assertSame($error, $sort->error);
        $this->assertSame(self::ROWS, $sort->sortRows(self::ROWS));
    }

    public function testSortRowsIsNaturalCaseInsensitiveAndStable(): void
    {
        $byName = ListSort::fromQuery('name', self::ALLOWED)->sortRows(self::ROWS);
        $this->assertSame(['api', 'mail', 'WWW2', 'www10'], array_column($byName, 'name'));

        // Equal TTLs keep their original relative order.
        $byTtlDesc = ListSort::fromQuery('ttl:desc', self::ALLOWED)->sortRows(self::ROWS);
        $this->assertSame(['WWW2', 'api', 'www10', 'mail'], array_column($byTtlDesc, 'name'));
    }

    public function testIntegersCompareNumericallyAndStringsNeverAsNumbers(): void
    {
        $ints = [['ttl' => 3600], ['ttl' => 300], ['ttl' => 86400]];
        $this->assertSame([300, 3600, 86400], array_column(ListSort::fromQuery('ttl', self::ALLOWED)->sortRows($ints), 'ttl'));

        // Numeric-looking strings are compared as text, so the order is transitive
        $names = [['name' => '1e3x'], ['name' => '200'], ['name' => '1e3']];
        $this->assertSame(['1e3', '1e3x', '200'], array_column(ListSort::fromQuery('name', self::ALLOWED)->sortRows($names), 'name'));
    }

    public function testNullsSortFirst(): void
    {
        $rows = [['name' => 'b'], ['name' => null], ['name' => 'a']];

        $this->assertSame([null, 'a', 'b'], array_column(ListSort::fromQuery('name', self::ALLOWED)->sortRows($rows), 'name'));
    }

    public function testSortRowsReindexes(): void
    {
        $rows = [3 => ['name' => 'b'], 7 => ['name' => 'a']];

        $this->assertSame([['name' => 'b'], ['name' => 'a']], ListSort::fromQuery(null, self::ALLOWED)->sortRows($rows));
    }
}
