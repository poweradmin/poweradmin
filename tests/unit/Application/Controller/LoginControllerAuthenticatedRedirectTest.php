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
use Poweradmin\Application\Controller\LoginController;
use Poweradmin\Domain\Service\UserContextService;

/**
 * The login page and the dashboard must decide "is this user logged in?" the same
 * way. While the login page asked whether the session merely carries a userid and
 * the dashboard required that userid to be positive, a session holding a
 * non-positive one bounced between / and /login until the browser gave up, with
 * no way left to reach the login form.
 */
class LoginControllerAuthenticatedRedirectTest extends TestCase
{
    public function testRedirectsToDashboardWhenTheContextReportsAuthenticated(): void
    {
        unset($_SESSION['userid']);

        $context = $this->createMock(UserContextService::class);
        $context->method('isAuthenticated')->willReturn(true);

        $controller = $this->getMockBuilder(LoginController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['redirect', 'getUserContextService'])
            ->getMock();
        $controller->method('getUserContextService')->willReturn($context);
        $controller->expects($this->once())->method('redirect')->with('/');

        $controller->run();
    }
}
