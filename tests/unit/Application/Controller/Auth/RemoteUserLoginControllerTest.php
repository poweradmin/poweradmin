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
namespace Poweradmin\Tests\Unit\Application\Controller\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\Auth\RemoteUserLoginController;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

#[CoversClass(RemoteUserLoginController::class)]
class RemoteUserLoginControllerTest extends SeamControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->session->remove(SessionKeys::USERID);
        $this->session->set(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT, true);
        $this->session->set(SessionKeys::LOGIN_TOKEN, 'login-token');
    }

    private function continueWith(string $token): string
    {
        $this->post(['_token' => $token]);
        $controller = new RemoteUserLoginController([], $this->environment($this->configure()));
        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt->target;
        }
        $this->fail('the controller always redirects');
    }

    public function testContinueLiftsTheSignedOutMark(): void
    {
        $this->assertSame('/', $this->continueWith('login-token'));
        $this->assertFalse($this->session->has(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT));
    }

    /**
     * Without the login page's token another site could undo a logout.
     */
    public function testRequestWithoutTheLoginTokenKeepsTheUserSignedOut(): void
    {
        $this->assertSame('/login', $this->continueWith('forged'));
        $this->assertTrue($this->session->get(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT));
    }
}
