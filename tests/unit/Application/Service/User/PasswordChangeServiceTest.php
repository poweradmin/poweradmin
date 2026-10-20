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

namespace Poweradmin\Tests\Unit\Application\Service\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\UserAuthenticationService;
use Poweradmin\Application\Service\User\PasswordChangeService;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\UserCredentialWriterInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * The password changed is the signed-in account's: a session that only passed the
 * password step of an MFA login, or whose login name names another account, changes nothing.
 */
#[CoversClass(PasswordChangeService::class)]
class PasswordChangeServiceTest extends TestCase
{
    private const USER_ID = 42;

    private UserLookupInterface&UserCredentialWriterInterface&MockObject $users;

    protected function setUp(): void
    {
        $users = $this->createMockForIntersectionOfInterfaces([UserLookupInterface::class, UserCredentialWriterInterface::class]);
        $this->assertInstanceOf(UserLookupInterface::class, $users);
        $this->assertInstanceOf(UserCredentialWriterInterface::class, $users);
        $this->users = $users;
        $this->users->method('findByUsername')->with('victim')->willReturn(new User(self::USER_ID, 'stored-hash', false));
    }

    public function testTheSignedInAccountChangesItsPassword(): void
    {
        $this->users->expects($this->once())->method('updatePassword')->with(self::USER_ID, 'new-hash')->willReturn(true);

        [$changed] = $this->service([SessionKeys::USERID => self::USER_ID, SessionKeys::USERLOGIN => 'victim'])->changePassword('old', 'new');

        $this->assertTrue($changed);
    }

    public function testASessionWaitingForItsSecondFactorChangesNothing(): void
    {
        $this->users->expects($this->never())->method('updatePassword');

        [$changed] = $this->service([SessionKeys::PENDING_USERID => self::USER_ID, SessionKeys::USERLOGIN => 'victim'])->changePassword('old', 'new');

        $this->assertFalse($changed);
    }

    public function testALoginNameOfAnotherAccountChangesNothing(): void
    {
        $this->users->expects($this->never())->method('updatePassword');

        [$changed] = $this->service([SessionKeys::USERID => 7, SessionKeys::USERLOGIN => 'victim'])->changePassword('old', 'new');

        $this->assertFalse($changed);
    }

    /** @param array<string, mixed> $session */
    private function service(array $session): PasswordChangeService
    {
        $auth = $this->createMock(UserAuthenticationService::class);
        $auth->method('verifyPassword')->willReturn(true);
        $auth->method('hashPassword')->willReturn('new-hash');

        return new PasswordChangeService($this->users, $auth, new UserContextService(new ArraySession($session)));
    }
}
