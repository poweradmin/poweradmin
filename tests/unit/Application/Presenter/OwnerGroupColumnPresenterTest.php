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

namespace Poweradmin\Tests\Unit\Application\Presenter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\OwnerGroupColumnPresenter;

#[CoversClass(OwnerGroupColumnPresenter::class)]
class OwnerGroupColumnPresenterTest extends TestCase
{
    public function testEmptyOwners(): void
    {
        $result = OwnerGroupColumnPresenter::presentOwners([], []);
        $this->assertSame([], $result['visible']);
        $this->assertSame(0, $result['remaining_count']);
        $this->assertSame('', $result['remaining_label']);
    }

    public function testTwoOwnersAllVisible(): void
    {
        $result = OwnerGroupColumnPresenter::presentOwners(
            [3 => 'alice', 7 => 'bob'],
            [3 => 'Alice A', 7 => '']
        );
        $this->assertSame(
            [['name' => 'alice', 'full_name' => 'Alice A'], ['name' => 'bob', 'full_name' => '']],
            $result['visible']
        );
        $this->assertSame(0, $result['remaining_count']);
    }

    public function testOverflowOwnersUseFullNameLabels(): void
    {
        $result = OwnerGroupColumnPresenter::presentOwners(
            ['a' => 'alice', 'b' => 'bob', 'c' => 'carol', 'd' => 'dave', 'e' => 'eve'],
            ['a' => 'Alice A', 'b' => '', 'c' => 'Carol C', 'd' => '', 'e' => 'Eve E']
        );
        $this->assertCount(2, $result['visible']);
        $this->assertSame(3, $result['remaining_count']);
        $this->assertSame('Carol C (carol), dave, Eve E (eve)', $result['remaining_label']);
    }

    public function testMissingFullNameKeyFallsBackToUsername(): void
    {
        $result = OwnerGroupColumnPresenter::presentOwners(
            [0 => 'alice', 1 => 'bob', 2 => 'carol'],
            [0 => 'Alice A']
        );
        $this->assertSame('carol', $result['remaining_label']);
    }

    public function testZeroStringFullNameTreatedAsAbsent(): void
    {
        $result = OwnerGroupColumnPresenter::presentOwners(
            [0 => 'alice', 1 => 'bob', 2 => 'carol'],
            [0 => '0', 1 => '0', 2 => '0']
        );
        $this->assertSame('carol', $result['remaining_label']);
        $this->assertSame('0', $result['visible'][0]['full_name']);
    }

    public function testGroupsEmpty(): void
    {
        $result = OwnerGroupColumnPresenter::presentGroups([]);
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['visible']);
        $this->assertSame(0, $result['remaining_count']);
    }

    public function testGroupsOverflow(): void
    {
        $result = OwnerGroupColumnPresenter::presentGroups(['g1', 'g2', 'g3', 'g4']);
        $this->assertSame(4, $result['count']);
        $this->assertSame(['g1', 'g2'], $result['visible']);
        $this->assertSame(2, $result['remaining_count']);
        $this->assertSame('g3, g4', $result['remaining_label']);
    }
}
