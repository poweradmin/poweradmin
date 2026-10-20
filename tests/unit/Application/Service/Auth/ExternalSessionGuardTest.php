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

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\ExternalSessionGuard;
use Poweradmin\Domain\Repository\AuthUserLookupInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;

#[CoversClass(ExternalSessionGuard::class)]
class ExternalSessionGuardTest extends TestCase
{
    public function testDisabledAccountEndsTheSession(): void
    {
        $session = new ArraySession();
        $session->set(SessionKeys::USERID, 7);

        $lookup = $this->createMock(AuthUserLookupInterface::class);
        $lookup->expects($this->once())->method('isActiveUser')->with(7)->willReturn(false);

        $this->assertFalse((new ExternalSessionGuard($lookup, $session))->accountIsActive());
    }

    public function testActiveAccountKeepsTheSession(): void
    {
        $session = new ArraySession();
        $session->set(SessionKeys::USERID, 7);

        $lookup = $this->createMock(AuthUserLookupInterface::class);
        $lookup->method('isActiveUser')->willReturn(true);

        $this->assertTrue((new ExternalSessionGuard($lookup, $session))->accountIsActive());
    }

    public function testAccountAwaitingMfaIsCheckedToo(): void
    {
        $session = new ArraySession();
        $session->set(SessionKeys::PENDING_USERID, 9);

        $lookup = $this->createMock(AuthUserLookupInterface::class);
        $lookup->expects($this->once())->method('isActiveUser')->with(9)->willReturn(false);

        $this->assertFalse((new ExternalSessionGuard($lookup, $session))->accountIsActive());
    }

    public function testDisabledPendingAccountIsCaughtBehindAnActiveSignedInOne(): void
    {
        $session = new ArraySession();
        $session->set(SessionKeys::USERID, 7);
        $session->set(SessionKeys::PENDING_USERID, 9);

        $lookup = $this->createMock(AuthUserLookupInterface::class);
        $lookup->method('isActiveUser')->willReturnMap([[7, true], [9, false]]);

        $this->assertFalse((new ExternalSessionGuard($lookup, $session))->accountIsActive());
    }

    public function testSessionWithoutAnAccountIsLeftToTheLoginFlow(): void
    {
        $lookup = $this->createMock(AuthUserLookupInterface::class);
        $lookup->expects($this->never())->method('isActiveUser');

        $this->assertTrue((new ExternalSessionGuard($lookup, new ArraySession()))->accountIsActive());
    }
}
