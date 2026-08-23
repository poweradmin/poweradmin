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


namespace Poweradmin\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Enum\SortDirection;

class SortDirectionTest extends TestCase
{
    public function testNormalisesCase(): void
    {
        $this->assertSame(SortDirection::ASC, SortDirection::fromRequest('asc'));
        $this->assertSame(SortDirection::DESC, SortDirection::fromRequest('desc'));
        $this->assertSame(SortDirection::DESC, SortDirection::fromRequest(' DeSc '));
    }

    public function testFallsBackToAscOnGarbage(): void
    {
        $this->assertSame(SortDirection::ASC, SortDirection::fromRequest('sideways'));
        $this->assertSame(SortDirection::ASC, SortDirection::fromRequest(null));
        $this->assertSame(SortDirection::ASC, SortDirection::fromRequest(''));
    }

    public function testHonoursExplicitDefault(): void
    {
        $this->assertSame(SortDirection::DESC, SortDirection::fromRequest('nope', SortDirection::DESC));
    }

    public function testIsValidIsCaseSensitive(): void
    {
        $this->assertTrue(SortDirection::isValid('ASC'));
        $this->assertFalse(SortDirection::isValid('asc'));
    }

    /**
     * These values are interpolated into ORDER BY, so nothing else may get through.
     */
    public function testValuesAreSqlSafe(): void
    {
        foreach (SortDirection::cases() as $case) {
            $this->assertMatchesRegularExpression('/^(ASC|DESC)$/', $case->value);
        }
        $this->assertSame('ASC', SortDirection::fromRequest('ASC; DROP TABLE users')->value);
    }
}
