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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\PasswordChangeService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;

/**
 * The password changed is the signed-in account's: a session that only passed the
 * password step of an MFA login, or whose login name names another account, changes nothing.
 */
#[CoversClass(PasswordChangeService::class)]
class PasswordChangeServiceTest extends TestCase
{
    private const USER_ID = 42;

    private UserRepository&MockObject $users;

    protected function setUp(): void
    {
        UserContextService::clearApiUserContext();
        $this->users = $this->createMock(UserRepository::class);
        $this->users->method('findByUsername')->with('victim')->willReturn(new User(self::USER_ID, 'stored-hash', false));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
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

    /** A borrowed session must not be able to guess the current password without limit */
    public function testAWrongCurrentPasswordIsCountedOnTheReauthStage(): void
    {
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(false);
        $attempts->expects($this->once())->method('recordAttempt')
            ->with('victim', '203.0.113.5', false, LoginAttemptService::STAGE_REAUTH, self::USER_ID);
        $this->users->expects($this->never())->method('updatePassword');

        [$changed] = $this->service([SessionKeys::USERID => self::USER_ID, SessionKeys::USERLOGIN => 'victim'], false, $attempts)->changePassword('guess', 'new');

        $this->assertFalse($changed);
    }

    public function testALockedReauthStageRefusesWithoutCheckingThePassword(): void
    {
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(true);
        $attempts->expects($this->never())->method('recordAttempt');
        $this->users->expects($this->never())->method('updatePassword');

        [$changed, $message] = $this->service([SessionKeys::USERID => self::USER_ID, SessionKeys::USERLOGIN => 'victim'], true, $attempts)->changePassword('old', 'new');

        $this->assertFalse($changed);
        $this->assertSame('Too many failed attempts. Please try again later.', $message);
    }

    /** @param array<string, mixed> $session */
    private function service(array $session, bool $passwordMatches = true, ?LoginAttemptService $attempts = null): PasswordChangeService
    {
        $_SESSION = $session;
        $auth = $this->createMock(UserAuthenticationService::class);
        $auth->method('verifyPassword')->willReturn($passwordMatches);
        $auth->method('hashPassword')->willReturn('new-hash');

        return new PasswordChangeService($this->users, $auth, new UserContextService(), $attempts, '203.0.113.5');
    }
}
