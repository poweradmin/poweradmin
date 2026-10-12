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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\MfaVerifyController;
use Poweradmin\Domain\Service\UserContextService;
use ReflectionClass;

/**
 * The second factor checked, and the address a code is sent to, belong to the pending
 * login whenever there is one, never to a user id or address left in the session.
 */
class MfaVerifyControllerTest extends TestCase
{
    private array $savedSession = [];

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
    }

    public function testThePendingLoginIsTheChallengedAccount(): void
    {
        $_SESSION = ['pending_userid' => '42', 'userlogin' => 'victim'];

        $this->assertSame(42, $this->invoke('challengedUserId'));
    }

    public function testTheSignedInAccountIsChallengedWhenNoLoginIsPending(): void
    {
        $_SESSION = ['userid' => 7, 'userlogin' => 'admin'];

        $this->assertSame(7, $this->invoke('challengedUserId'));
    }

    public function testThePendingLoginOfTheSignedInAccountIsChallenged(): void
    {
        $_SESSION = ['userid' => 42, 'pending_userid' => '42', 'userlogin' => 'victim'];

        $this->assertSame(42, $this->invoke('challengedUserId'));
    }

    public function testTheCodeGoesToThePendingLoginNotToAnAddressLeftInTheSession(): void
    {
        $_SESSION = ['pending_userid' => 42, 'pending_email' => 'pending@example.com', 'email' => 'previous@example.com'];

        $this->assertSame('pending@example.com', $this->invoke('challengedEmail'));
    }

    public function testAPendingLoginWithoutAnAddressGetsNoneFromTheSession(): void
    {
        $_SESSION = ['pending_userid' => 42, 'email' => 'previous@example.com'];

        $this->assertNull($this->invoke('challengedEmail'));
    }

    public function testTheSignedInAddressIsUsedWhenNoLoginIsPending(): void
    {
        $_SESSION = ['userid' => 7, 'email' => 'admin@example.com'];

        $this->assertSame('admin@example.com', $this->invoke('challengedEmail'));
    }

    private function invoke(string $method): mixed
    {
        $reflection = new ReflectionClass(MfaVerifyController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('userContextService')->setValue($controller, new UserContextService());

        return $reflection->getMethod($method)->invoke($controller);
    }
}
