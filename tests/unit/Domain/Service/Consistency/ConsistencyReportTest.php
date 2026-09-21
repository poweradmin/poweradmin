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

namespace Poweradmin\Tests\Unit\Domain\Service\Consistency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Consistency\ConsistencyReport;
use RuntimeException;

#[CoversClass(ConsistencyReport::class)]
class ConsistencyReportTest extends TestCase
{
    public function testBuildReportsSuccessWithoutFindingsAndCountsThemOtherwise(): void
    {
        $this->assertSame(
            ['status' => 'success', 'message' => 'all clear', 'data' => []],
            ConsistencyReport::build([], 'all clear', 'error', '%d broken')
        );
        $this->assertSame(
            ['status' => 'error', 'message' => '2 broken', 'data' => [['id' => 1], ['id' => 2]]],
            ConsistencyReport::build([['id' => 1], ['id' => 2]], 'all clear', 'error', '%d broken')
        );
    }

    public function testRepairEachCountsFailuresAndExceptionsWithoutStopping(): void
    {
        $seen = [];
        $repair = static function (int $id) use (&$seen): bool {
            $seen[] = $id;
            if ($id === 2) {
                throw new RuntimeException('boom');
            }
            return $id !== 3;
        };

        $this->assertSame(['fixed' => 2, 'failed' => 2], ConsistencyReport::repairEach([1, 2, 3, 4], $repair, 'fixed'));
        $this->assertSame([1, 2, 3, 4], $seen);
    }

    public function testTallyPicksTheMessageByOutcome(): void
    {
        $this->assertSame(
            ['status' => 'success', 'message' => 'nothing'],
            ConsistencyReport::tally(['fixed' => 0, 'failed' => 0], 'fixed', 'nothing', 'all %d', '%d ok; %d failed')
        );
        $this->assertSame(
            ['status' => 'success', 'message' => 'all 3'],
            ConsistencyReport::tally(['fixed' => 3, 'failed' => 0], 'fixed', 'nothing', 'all %d', '%d ok; %d failed')
        );
        $this->assertSame(
            ['status' => 'warning', 'message' => '1 ok; 2 failed'],
            ConsistencyReport::tally(['fixed' => 1, 'failed' => 2], 'fixed', 'nothing', 'all %d', '%d ok; %d failed')
        );
    }

    public function testFindingIdsCastsEveryId(): void
    {
        $this->assertSame([5, 6], ConsistencyReport::findingIds(['data' => [['id' => '5'], ['id' => 6]]]));
    }

    public function testDefaultSoaContentNamesTheZoneAndDatesTheSerial(): void
    {
        $this->assertSame(
            'ns1.example.com hostmaster.example.com ' . date('Ymd') . '01 28800 7200 604800 86400',
            ConsistencyReport::defaultSoaContent('example.com')
        );
    }
}
