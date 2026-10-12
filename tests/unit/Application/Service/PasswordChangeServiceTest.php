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

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->users = $this->createMock(UserRepository::class);
        $this->users->method('findByUsername')->with('victim')->willReturn(new User(self::USER_ID, 'stored-hash', false));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheSignedInAccountChangesItsPassword(): void
    {
        $_SESSION = ['userid' => self::USER_ID, 'userlogin' => 'victim'];
        $this->users->expects($this->once())->method('updatePassword')->with(self::USER_ID, 'new-hash')->willReturn(true);

        [$changed] = $this->service()->changePassword('old', 'new');

        $this->assertTrue($changed);
    }

    public function testASessionWaitingForItsSecondFactorChangesNothing(): void
    {
        $_SESSION = ['pending_userid' => self::USER_ID, 'userlogin' => 'victim'];
        $this->users->expects($this->never())->method('updatePassword');

        [$changed] = $this->service()->changePassword('old', 'new');

        $this->assertFalse($changed);
    }

    public function testALoginNameOfAnotherAccountChangesNothing(): void
    {
        $_SESSION = ['userid' => 7, 'userlogin' => 'victim'];
        $this->users->expects($this->never())->method('updatePassword');

        [$changed] = $this->service()->changePassword('old', 'new');

        $this->assertFalse($changed);
    }

    private function service(): PasswordChangeService
    {
        $auth = $this->createMock(UserAuthenticationService::class);
        $auth->method('verifyPassword')->willReturn(true);
        $auth->method('hashPassword')->willReturn('new-hash');

        return new PasswordChangeService($this->users, $auth, new UserContextService());
    }
}
