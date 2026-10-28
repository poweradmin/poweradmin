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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\ResultPaginator;
use Poweradmin\Infrastructure\Utility\SortHelper;

#[CoversClass(SortHelper::class)]
#[CoversClass(ResultPaginator::class)]
class ReverseRecordNameSortTest extends TestCase
{
    public function testOnlyInAddrArpaZonesAreSortedByAddress(): void
    {
        $this->assertTrue(SortHelper::isIpv4ReverseZone('168.192.in-addr.arpa'));
        $this->assertTrue(SortHelper::isIpv4ReverseZone('0/25.2.0.192.IN-ADDR.ARPA.'));
        $this->assertTrue(SortHelper::isIpv4ReverseZone('in-addr.arpa'));
        $this->assertFalse(SortHelper::isIpv4ReverseZone('example.com'));
        $this->assertFalse(SortHelper::isIpv4ReverseZone('0.0.0.0.ip6.arpa'));
        $this->assertFalse(SortHelper::isIpv4ReverseZone('notin-addr.arpa'));
    }

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return ['mysql' => ['mysql'], 'mysqli' => ['mysqli'], 'pgsql' => ['pgsql'], 'sqlite' => ['sqlite']];
    }

    public function testAReverseViewVariantIsSortedByAddress(): void
    {
        // IP order puts 9.2 before 10.1; a plain string sort would not
        $records = [['name' => '1.10.0.192.in-addr.arpa'], ['name' => '2.9.0.192.in-addr.arpa']];
        $this->assertSame(
            ['2.9.0.192.in-addr.arpa', '1.10.0.192.in-addr.arpa'],
            array_column(ResultPaginator::sortRecords($records, 'name', 'ASC', '0.192.in-addr.arpa..internal'), 'name')
        );

        $this->assertTrue(SortHelper::isIpv4ReverseZone('2.0.192.in-addr.arpa..internal'));
        $this->assertTrue(SortHelper::sortsByAddress('2.0.192.in-addr.arpa..internal'));
        $this->assertFalse(SortHelper::sortsByAddress('example.com..internal'));
    }

    public function testSqlAndApiModeFallBackTogetherForAZoneNameThatIsNotPlain(): void
    {
        $zone = "x'y.2.0.192.in-addr.arpa";
        $records = [['name' => 'b.5.' . $zone, 'type' => 'PTR'], ['name' => 'a.10.' . $zone, 'type' => 'PTR']];

        $this->assertFalse(SortHelper::sortsByAddress($zone));
        $this->assertTrue(SortHelper::sortsByAddress('ptr-sort-4f2a.2.0.192.IN-ADDR.ARPA.'));
        $this->assertSame(
            SortHelper::getNaturalSortOrder('records', 'mysql'),
            SortHelper::getReverseRecordNameSortOrder('records', 'mysql', 'ASC', $zone)
        );
        // IP order would put b.5 first; the natural order puts a.10 first
        $this->assertSame(
            array_column(ResultPaginator::sort($records, 'name'), 'name'),
            array_column(ResultPaginator::sortRecords($records, 'name', 'ASC', $zone), 'name')
        );
    }

    public function testTheZoneDecidesHowManyLabelsAreCompared(): void
    {
        $this->assertSame(4, SortHelper::reverseZoneIpPositions('in-addr.arpa'));
        $this->assertSame(3, SortHelper::reverseZoneIpPositions('192.in-addr.arpa'));
        $this->assertSame(2, SortHelper::reverseZoneIpPositions('168.192.IN-ADDR.ARPA.'));
        $this->assertSame(1, SortHelper::reverseZoneIpPositions('2.168.192.in-addr.arpa'));
        $this->assertSame(1, SortHelper::reverseZoneIpPositions('0/25.2.0.192.in-addr.arpa'));
        $this->assertSame(1, SortHelper::reverseZoneIpPositions('1.0/25.2.0.192.in-addr.arpa'));
    }

    #[DataProvider('drivers')]
    public function testTheClauseCutsOffTheZoneAndFallsBackToTheNaturalOrder(string $driver): void
    {
        $sql = SortHelper::getReverseRecordNameSortOrder('records', $driver, 'ASC', '168.192.in-addr.arpa');

        $this->assertSame(2, substr_count($sql, "= '' THEN -1"));
        $this->assertStringContainsString("= '.168.192.in-addr.arpa'", $sql);
        $this->assertStringContainsString('ELSE 1000000 END ASC', $sql);
        $this->assertStringEndsWith(SortHelper::getNaturalSortOrder('records', $driver, 'ASC'), $sql);
        $this->assertStringNotContainsString('DESC', $sql);
    }

    #[DataProvider('drivers')]
    public function testASlash24ZoneNeedsOneTerm(string $driver): void
    {
        $sql = SortHelper::getReverseRecordNameSortOrder('records', $driver, 'DESC', '2.0.192.IN-ADDR.ARPA.');

        $this->assertSame(1, substr_count($sql, 'ELSE 1000000 END DESC'));
        $this->assertStringContainsString("= '.2.0.192.in-addr.arpa'", $sql);
        $this->assertStringEndsWith(SortHelper::getNaturalSortOrder('records', $driver, 'DESC'), $sql);
    }

    public function testEachDriverUsesItsOwnLabelSplitting(): void
    {
        $zone = '168.192.in-addr.arpa';
        $this->assertStringContainsString("SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT('....'", SortHelper::getReverseRecordNameSortOrder('records', 'mysql', 'ASC', $zone));
        $this->assertStringContainsString("REVERSE(SPLIT_PART(REVERSE(", SortHelper::getReverseRecordNameSortOrder('records', 'pgsql', 'ASC', $zone));
        $this->assertStringContainsString("RTRIM(", SortHelper::getReverseRecordNameSortOrder('records', 'sqlite', 'ASC', $zone));
        $this->assertStringContainsString("CHAR_LENGTH(records.name)", SortHelper::getReverseRecordNameSortOrder('records', 'mysql', 'ASC', $zone));
        $this->assertStringContainsString("LENGTH(records.name) - 21", SortHelper::getReverseRecordNameSortOrder('records', 'pgsql', 'ASC', $zone));
    }

    public function testMysqlReadsLeadingDigitsOnly(): void
    {
        $sql = SortHelper::getReverseRecordNameSortOrder('records', 'mysql', 'ASC', '2.0.192.in-addr.arpa');

        $this->assertStringContainsString('CAST(SUBSTR(', $sql);
        $this->assertStringContainsString('AS UNSIGNED)', $sql);
        $this->assertSame([1], SortHelper::reverseRecordNameKey('1e5.2.0.192.in-addr.arpa', '2.0.192.in-addr.arpa'));
    }

    public function testTheTableNameIsQualifiedThroughout(): void
    {
        $sql = SortHelper::getReverseRecordNameSortOrder('pdns.records', 'pgsql', 'ASC', '168.192.in-addr.arpa');

        $this->assertStringNotContainsString(' name', str_replace('pdns.records.name', '', $sql));
    }

    public function testAnUnknownDriverOrAnOddZoneKeepsTheNaturalOrder(): void
    {
        $this->assertSame('records.name ASC', SortHelper::getReverseRecordNameSortOrder('records', 'other'));
        $this->assertSame(
            SortHelper::getNaturalSortOrder('records', 'mysql'),
            SortHelper::getReverseRecordNameSortOrder('records', 'mysql', 'ASC', "x'y.in-addr.arpa")
        );
        $this->assertSame(
            SortHelper::getNaturalSortOrder('records', 'mysql'),
            SortHelper::getReverseRecordNameSortOrder('records', 'mysql', 'ASC', 'example.com')
        );
    }

    public function testTheKeyHoldsTheLabelsBelowTheZoneRightToLeft(): void
    {
        $this->assertSame([10, 5], SortHelper::reverseRecordNameKey('5.10.168.192.in-addr.arpa', '168.192.in-addr.arpa'));
        $this->assertSame([-1, -1], SortHelper::reverseRecordNameKey('168.192.IN-ADDR.ARPA.', '168.192.in-addr.arpa'));
        $this->assertSame([-1], SortHelper::reverseRecordNameKey('2.0.192.in-addr.arpa', '2.0.192.in-addr.arpa'));
        $this->assertSame([0], SortHelper::reverseRecordNameKey('5.0/25.2.0.192.in-addr.arpa', '2.0.192.in-addr.arpa'));
        $this->assertSame([5], SortHelper::reverseRecordNameKey('5.0/25.2.0.192.in-addr.arpa', '0/25.2.0.192.in-addr.arpa'));
        $this->assertSame([1000000], SortHelper::reverseRecordNameKey('*.2.0.192.in-addr.arpa', '2.0.192.in-addr.arpa'));
        $this->assertSame([192, 0, 2, 9], SortHelper::reverseRecordNameKey('9.2.0.192.in-addr.arpa'));
        $this->assertSame([-1], SortHelper::reverseRecordNameKey('foo.example.com', '2.0.192.in-addr.arpa'));
    }

    public function testTheZoneSuffixMustBeAWholeSuffixOfTheName(): void
    {
        $this->assertSame([-1], SortHelper::reverseRecordNameKey('5.x2.0.192.in-addr.arpa', '2.0.192.in-addr.arpa'));
        $this->assertSame([5], SortHelper::reverseRecordNameKey('5.2.0.192.IN-ADDR.ARPA', '2.0.192.in-addr.arpa'));
    }

    public function testRecordsSortInIpOrderWithTheApexFirst(): void
    {
        $names = [
            '5.10.168.192.in-addr.arpa',
            '5.2.168.192.in-addr.arpa',
            '168.192.in-addr.arpa',
            '10.168.192.in-addr.arpa',
            '_ptr.2.168.192.in-addr.arpa',
            '200.2.168.192.in-addr.arpa',
            '5.0/25.2.168.192.in-addr.arpa',
            '1.2.168.192.in-addr.arpa',
            '*.10.168.192.in-addr.arpa',
        ];
        $records = array_map(fn(string $n): array => ['name' => $n, 'type' => $n === '168.192.in-addr.arpa' ? 'SOA' : 'PTR'], $names);
        $expected = [
            '168.192.in-addr.arpa',
            '5.0/25.2.168.192.in-addr.arpa',
            '1.2.168.192.in-addr.arpa',
            '5.2.168.192.in-addr.arpa',
            '200.2.168.192.in-addr.arpa',
            '_ptr.2.168.192.in-addr.arpa',
            '10.168.192.in-addr.arpa',
            '5.10.168.192.in-addr.arpa',
            '*.10.168.192.in-addr.arpa',
        ];

        $asc = array_column(ResultPaginator::sortRecords($records, 'name', 'ASC', '168.192.in-addr.arpa'), 'name');
        $desc = array_column(ResultPaginator::sortRecords($records, 'name', 'DESC', '168.192.in-addr.arpa'), 'name');

        $this->assertSame($expected, $asc);
        $this->assertSame(array_merge(['168.192.in-addr.arpa'], array_reverse(array_slice($expected, 1))), $desc);
    }

    public function testTheApexNsLeadsOtherNsInBothDirections(): void
    {
        $zone = '168.192.in-addr.arpa';
        $records = [
            ['name' => '10.' . $zone, 'type' => 'NS'],
            ['name' => $zone, 'type' => 'NS'],
            ['name' => '5.10.' . $zone, 'type' => 'PTR'],
            ['name' => $zone, 'type' => 'SOA'],
        ];

        foreach (['ASC', 'DESC'] as $direction) {
            $sorted = ResultPaginator::sortRecords($records, 'name', $direction, $zone);
            $this->assertSame([$zone, $zone, '10.' . $zone, '5.10.' . $zone], array_column($sorted, 'name'), $direction);
            $this->assertSame(['SOA', 'NS', 'NS', 'PTR'], array_column($sorted, 'type'), $direction);
        }
    }

    public function testAForwardZoneStillSortsNaturally(): void
    {
        $records = array_map(fn(string $n): array => ['name' => $n, 'type' => 'A'], ['5.10.example.com', '5.2.example.com', '1.2.example.com']);

        $sorted = array_column(ResultPaginator::sortRecords($records, 'name', 'ASC', 'example.com'), 'name');

        $this->assertSame(['1.2.example.com', '5.2.example.com', '5.10.example.com'], $sorted);
    }
}
