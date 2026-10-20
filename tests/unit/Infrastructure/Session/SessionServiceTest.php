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

declare(strict_types=1);

namespace unit\Infrastructure\Session;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Infrastructure\Session\FlashMessage;
use Poweradmin\Infrastructure\Session\SessionService;

/**
 * Pins which session keys the login message occupies and which keys a logout
 * clears; both are read by the login page and the authenticator.
 */
class SessionServiceTest extends TestCase
{
    public function testStartSessionStoresTheLoginMessageAndItsType(): void
    {
        $session = new ArraySession();

        (new SessionService($session))->startSession(new FlashMessage('Session expired', 'danger'));

        $this->assertSame('Session expired', $session->get(SessionKeys::LOGIN_MESSAGE));
        $this->assertSame('danger', $session->get(SessionKeys::LOGIN_MESSAGE_TYPE));
    }

    public function testEndSessionDropsTheAuthAndMfaKeys(): void
    {
        $session = new ArraySession([
            AuthFlowSessionKeys::MFA_STATE => 'pending',
            AuthFlowSessionKeys::MFA_REQUIRED => true,
            AuthFlowSessionKeys::MFA_TOKEN => 'token',
            SessionKeys::AUTHENTICATED => true,
            SessionKeys::USERID => 7,
            SessionKeys::USERLOGIN => 'alice',
            SessionKeys::USERPWD => 'encrypted',
            SessionKeys::USERLANG => 'en_EN',
        ]);

        (new SessionService($session))->endSession();

        foreach (
            [
            AuthFlowSessionKeys::MFA_STATE,
            AuthFlowSessionKeys::MFA_REQUIRED,
            AuthFlowSessionKeys::MFA_TOKEN,
            SessionKeys::AUTHENTICATED,
            SessionKeys::USERID,
            SessionKeys::USERLOGIN,
            SessionKeys::USERPWD,
            ] as $key
        ) {
            $this->assertFalse($session->has($key), $key . ' must be gone after a logout');
        }
    }

    /**
     * The session is wiped only when one is open; a logout on a closed session
     * must not try to regenerate an id that does not exist.
     */
    public function testEndSessionWipesTheSessionOnlyWhileItIsOpen(): void
    {
        $open = new ArraySession([SessionKeys::USERLANG => 'de_DE']);
        (new SessionService($open))->endSession();
        $this->assertSame([], $open->all());

        $closed = new ArraySession([SessionKeys::USERLANG => 'de_DE']);
        $closed->writeClose();
        (new SessionService($closed))->endSession();
        $this->assertSame(['userlang' => 'de_DE'], $closed->all());
    }
}
