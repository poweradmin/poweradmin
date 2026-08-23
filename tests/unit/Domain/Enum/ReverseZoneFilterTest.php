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
use Poweradmin\Domain\Enum\ReverseZoneFilter;

class ReverseZoneFilterTest extends TestCase
{
    public function testMembership(): void
    {
        $this->assertTrue(ReverseZoneFilter::ALL->includesIpv4());
        $this->assertTrue(ReverseZoneFilter::ALL->includesIpv6());
        $this->assertTrue(ReverseZoneFilter::IPV4->includesIpv4());
        $this->assertFalse(ReverseZoneFilter::IPV4->includesIpv6());
        $this->assertFalse(ReverseZoneFilter::IPV6->includesIpv4());
        $this->assertTrue(ReverseZoneFilter::IPV6->includesIpv6());
    }

    /**
     * A case selecting neither family would build an empty `AND ()` clause and
     * fail the zone query with a syntax error.
     */
    public function testEveryCaseSelectsSomething(): void
    {
        foreach (ReverseZoneFilter::cases() as $case) {
            $this->assertTrue(
                $case->includesIpv4() || $case->includesIpv6(),
                "{$case->value} selects no address family"
            );
        }
    }

    public function testRejectsNonStrings(): void
    {
        $this->assertSame(ReverseZoneFilter::ALL, ReverseZoneFilter::fromRequest(['ipv4']));
        $this->assertSame(ReverseZoneFilter::ALL, ReverseZoneFilter::fromRequest(null));
        $this->assertSame(ReverseZoneFilter::ALL, ReverseZoneFilter::fromRequest('bogus'));
        $this->assertSame(ReverseZoneFilter::IPV6, ReverseZoneFilter::fromRequest('ipv6'));
    }
}
