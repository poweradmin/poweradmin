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
use Poweradmin\Application\Controller\Auth\LogoutController;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Web\RedirectService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Infrastructure\Session\SessionService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The web server signs the user in again on the next request, so logging out
 * must leave the fresh session marked signed out, whichever way it ends.
 */
#[CoversClass(LogoutController::class)]
class LogoutControllerRemoteUserTest extends SeamControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->session->set(SessionKeys::AUTH_METHOD_USED, 'remote_user');
        $this->factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $this->factory->method('sessionService')->willReturn(new SessionService($this->session));
    }

    private function logOut(array $remoteUser): RequestHalted
    {
        $controller = new LogoutController([], true, $this->environment($this->configure(['remote_user' => $remoteUser])));
        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }
        $this->fail('logout always redirects');
    }

    public function testWithoutLogoutUrlTheLoginPageShowsTheUserSignedOut(): void
    {
        $halt = $this->logOut([]);

        $this->assertSame('/login', $halt->target);
        $this->assertFalse($this->session->has(SessionKeys::USERID));
        $this->assertTrue($this->session->get(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT));
        $this->assertSame('You have logged out.', $this->session->get(SessionKeys::LOGIN_MESSAGE));
    }

    public function testLogoutUrlEndsTheWebServerSession(): void
    {
        $redirect = $this->createMock(RedirectService::class);
        $redirect->expects($this->once())->method('redirectTo')->with('https://auth.example.com/logout')
            ->willThrowException(new RequestHalted(RequestHalted::KIND_REDIRECT, 'https://auth.example.com/logout'));
        $this->factory->method('redirectService')->willReturn($redirect);

        $halt = $this->logOut(['logout_url' => 'https://auth.example.com/logout']);

        $this->assertSame('https://auth.example.com/logout', $halt->target);
        $this->assertFalse($this->session->has(SessionKeys::USERID));
        $this->assertTrue($this->session->get(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT));
    }

    public function testNonHttpLogoutUrlIsIgnored(): void
    {
        $redirect = $this->createMock(RedirectService::class);
        $redirect->expects($this->never())->method('redirectTo');
        $this->factory->method('redirectService')->willReturn($redirect);

        $this->assertSame('/login', $this->logOut(['logout_url' => 'javascript:alert(1)'])->target);
    }

    public function testLogoutWhileTheSecondFactorIsPendingIsAWebServerLogout(): void
    {
        $this->session->remove(SessionKeys::AUTH_METHOD_USED);
        $this->session->remove(SessionKeys::USERID);
        $this->session->set(SessionKeys::PENDING_AUTH_METHOD_USED, 'remote_user');

        $this->assertSame('/login', $this->logOut([])->target);
        $this->assertTrue($this->session->get(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT));
    }
}
