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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\ResultPaginator;

class ResultPaginatorTest extends TestCase
{
    /**
     * Build a representative forward-zone record set for example.com where the
     * apex (@) holds SOA/NS/A/MX/TXT records alongside ordinary sub-records.
     */
    private function sampleRecords(): array
    {
        return [
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.10'],
            ['name' => 'example.com', 'type' => 'A', 'content' => '192.0.2.1'],
            ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com'],
            ['name' => 'alpha.example.com', 'type' => 'A', 'content' => '192.0.2.20'],
            ['name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.example.com'],
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com hostmaster.example.com 1 ...'],
            ['name' => 'beta.example.com', 'type' => 'A', 'content' => '192.0.2.30'],
            ['name' => 'example.com', 'type' => 'TXT', 'content' => 'v=spf1 -all'],
        ];
    }

    private function names(array $records): array
    {
        return array_map(fn($r) => $r['name'] . '/' . $r['type'], $records);
    }

    public function testApexRecordsArePinnedToTopWhenSortingByNameAsc(): void
    {
        $sorted = ResultPaginator::sortRecords($this->sampleRecords(), 'name', 'ASC', 'example.com');
        $order = $this->names($sorted);

        // SOA first, then NS, then remaining apex records, before any sub-records.
        $this->assertSame('example.com/SOA', $order[0]);
        $this->assertSame('example.com/NS', $order[1]);

        $apexBlock = array_slice($order, 0, 5);
        sort($apexBlock);
        $this->assertSame(
            ['example.com/A', 'example.com/MX', 'example.com/NS', 'example.com/SOA', 'example.com/TXT'],
            $apexBlock
        );

        // Sub-records follow, sorted alphabetically by name.
        $this->assertSame(
            ['alpha.example.com/A', 'beta.example.com/A', 'www.example.com/A'],
            array_slice($order, 5)
        );
    }

    public function testApexRecordsStayPinnedWhenSortingByNameDesc(): void
    {
        $sorted = ResultPaginator::sortRecords($this->sampleRecords(), 'name', 'DESC', 'example.com');
        $order = $this->names($sorted);

        $this->assertSame('example.com/SOA', $order[0]);
        $this->assertSame('example.com/NS', $order[1]);

        // Sub-records still follow the apex block, now in descending name order.
        $this->assertSame(
            ['www.example.com/A', 'beta.example.com/A', 'alpha.example.com/A'],
            array_slice($order, 5)
        );
    }

    public function testApexRecordsStayPinnedWhenSortingByOtherColumn(): void
    {
        $sorted = ResultPaginator::sortRecords($this->sampleRecords(), 'content', 'ASC', 'example.com');
        $order = $this->names($sorted);

        $this->assertSame('example.com/SOA', $order[0]);
        $this->assertSame('example.com/NS', $order[1]);
        $this->assertCount(5, array_filter($order, fn($n) => str_starts_with($n, 'example.com/')));

        // The apex block always occupies the first five positions.
        foreach (array_slice($order, 0, 5) as $entry) {
            $this->assertStringStartsWith('example.com/', $entry);
        }
    }

    public function testApexNameWithTrailingDotStillMatches(): void
    {
        $sorted = ResultPaginator::sortRecords($this->sampleRecords(), 'name', 'ASC', 'example.com.');
        $order = $this->names($sorted);

        foreach (array_slice($order, 0, 5) as $entry) {
            $this->assertStringStartsWith('example.com/', $entry);
        }
    }

    public function testEmptyDatasetIsReturnedUnchanged(): void
    {
        $this->assertSame([], ResultPaginator::sortRecords([], 'name', 'ASC', 'example.com'));
    }

    public function testPlainSortDoesNotPinApexRecords(): void
    {
        $sorted = ResultPaginator::sort($this->sampleRecords(), 'name', 'ASC');
        $order = $this->names($sorted);

        // Plain sort is purely alphabetical: apex (example.com) lands after alpha/beta.
        $this->assertSame('alpha.example.com/A', $order[0]);
    }
}
