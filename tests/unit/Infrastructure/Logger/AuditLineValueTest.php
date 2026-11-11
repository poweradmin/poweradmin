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

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Logger\AuditLineValue;

#[CoversClass(AuditLineValue::class)]
class AuditLineValueTest extends TestCase
{
    public function testAValueCannotPassForAFieldOrBreakTheLine(): void
    {
        $this->assertSame('x client_ip%3A203.0.113.9 1client_ip%3Ax?y', AuditLineValue::of("x client_ip:203.0.113.9 1client_ip:x\ny"));
    }

    public function testColonsAfterDigitsStayReadable(): void
    {
        $this->assertSame('2001:db8::1 at 12:30', AuditLineValue::of('2001:db8::1 at 12:30'));
    }

    public function testAUsernameHasEveryColonEncoded(): void
    {
        $this->assertSame('urn%3A1%3Aann?', AuditLineValue::user("urn:1:ann\t"));
    }

    public function testAUsernameWithASpaceCannotPassForAnotherUser(): void
    {
        $this->assertSame('admin%20evil', AuditLineValue::user('admin evil'));
        $this->assertSame('evil%20user%3Aadmin', AuditLineValue::user('evil user:admin'));
    }
}
