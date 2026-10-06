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
use Poweradmin\Infrastructure\Utility\TrustedProxyList;

#[CoversClass(TrustedProxyList::class)]
class TrustedProxyListTest extends TestCase
{
    public function testMatchesExactCidrAndWildcardEntries(): void
    {
        $list = new TrustedProxyList(['192.0.2.10', '198.51.100.0/24', '203.0.113.*', '2001:db8::/32']);

        $this->assertTrue($list->matches('192.0.2.10'));
        $this->assertTrue($list->matches('198.51.100.77'));
        $this->assertTrue($list->matches('203.0.113.5'));
        $this->assertTrue($list->matches('2001:db8:0:0::1'));
        $this->assertFalse($list->matches('192.0.2.11'));
        $this->assertFalse($list->matches('198.51.101.1'));
    }

    public function testPrivateAndLoopbackAddressesAreNotTrustedUnlessListed(): void
    {
        $list = new TrustedProxyList([]);

        $this->assertFalse($list->matches('127.0.0.1'));
        $this->assertFalse($list->matches('10.0.0.1'));
        $this->assertFalse($list->matches('::1'));
    }

    public function testNonStringAndMalformedEntriesNeverMatch(): void
    {
        $list = new TrustedProxyList([null, 42, '', '10.0.0.0/abc', '10.0.0.0/40']);

        $this->assertFalse($list->matches('10.0.0.1'));
    }
}
