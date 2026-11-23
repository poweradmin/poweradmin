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
    private array $savedSession = [];

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $this->users = $this->createMock(UserRepository::class);
        $this->users->method('findByUsername')->with('victim')->willReturn(new User(self::USER_ID, 'stored-hash', false));
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
    }

    public function testTheSignedInAccountChangesItsPassword(): void
    {
        $this->users->expects($this->once())->method('updatePassword')->with(self::USER_ID, 'new-hash')->willReturn(true);

        [$changed] = $this->changePassword(['userid' => self::USER_ID, 'userlogin' => 'victim']);

        $this->assertTrue($changed);
    }

    public function testASessionWaitingForItsSecondFactorChangesNothing(): void
    {
        $this->users->expects($this->never())->method('updatePassword');

        [$changed] = $this->changePassword(['pending_userid' => self::USER_ID, 'userlogin' => 'victim']);

        $this->assertFalse($changed);
    }

    public function testALoginNameOfAnotherAccountChangesNothing(): void
    {
        $this->users->expects($this->never())->method('updatePassword');

        [$changed] = $this->changePassword(['userid' => 7, 'userlogin' => 'victim']);

        $this->assertFalse($changed);
    }

    public function testAWrongCurrentPasswordStaysCountedAsAFailedSignIn(): void
    {
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->expects($this->once())->method('reserveAttempt')->with('victim', '203.0.113.9')->willReturn(true);
        $attempts->expects($this->never())->method('confirmAttempt');
        $attempts->expects($this->never())->method('recordAttempt');
        $this->users->expects($this->never())->method('updatePassword');

        [$changed] = $this->changePassword(['userid' => self::USER_ID, 'userlogin' => 'victim'], false, $attempts);

        $this->assertFalse($changed);
    }

    public function testARightCurrentPasswordClearsTheReservedAttempt(): void
    {
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->expects($this->once())->method('reserveAttempt')->with('victim', '203.0.113.9')->willReturn(true);
        $attempts->expects($this->once())->method('confirmAttempt')->with('victim', '203.0.113.9');
        $this->users->method('updatePassword')->willReturn(true);

        [$changed] = $this->changePassword(['userid' => self::USER_ID, 'userlogin' => 'victim'], true, $attempts);

        $this->assertTrue($changed);
    }

    public function testAnExhaustedReservationRefusesEvenTheRightPassword(): void
    {
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->expects($this->once())->method('reserveAttempt')->willReturn(false);
        $attempts->expects($this->never())->method('confirmAttempt');
        $this->users->expects($this->never())->method('updatePassword');

        [$changed] = $this->changePassword(['userid' => self::USER_ID, 'userlogin' => 'victim'], true, $attempts);

        $this->assertFalse($changed);
    }

    public function testALockedAccountIsRefusedEvenWithTheRightPassword(): void
    {
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->with('victim', '203.0.113.9')->willReturn(true);
        $attempts->expects($this->never())->method('reserveAttempt');
        $this->users->expects($this->never())->method('updatePassword');

        [$changed] = $this->changePassword(['userid' => self::USER_ID, 'userlogin' => 'victim'], true, $attempts);

        $this->assertFalse($changed);
    }

    private function changePassword(array $session, bool $passwordMatches = true, ?LoginAttemptService $attempts = null): array
    {
        $_SESSION = $session;
        $auth = $this->createMock(UserAuthenticationService::class);
        $auth->method('verifyPassword')->willReturn($passwordMatches);
        $auth->method('hashPassword')->willReturn('new-hash');

        return (new PasswordChangeService($this->users, $auth, new UserContextService(), $attempts, '203.0.113.9'))->changePassword('old', 'new');
    }
}
