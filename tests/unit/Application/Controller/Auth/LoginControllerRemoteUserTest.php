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
use Poweradmin\Application\Controller\Auth\LoginController;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;

#[CoversClass(LoginController::class)]
class LoginControllerRemoteUserTest extends SeamControllerTestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->session->remove(SessionKeys::USERID);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    private function renderLogin(array $remoteUser): array
    {
        $controller = new LoginController([], $this->environment($this->configure(['remote_user' => $remoteUser])));
        (new ReflectionMethod(LoginController::class, 'renderLogin'))->invoke($controller, '', '');

        return $this->renderedParams();
    }

    public function testOffersToContinueAsTheWebServerUser(): void
    {
        $_SERVER['REMOTE_USER'] = 'alice';

        $params = $this->renderLogin(['enabled' => true]);

        $this->assertSame('alice', $params['remote_user_name']);
        $this->assertFalse($params['hide_password_form']);
    }

    public function testPasswordFormIsHiddenOnlyWhileTheWebServerSignsSomeoneIn(): void
    {
        $_SERVER['REMOTE_USER'] = 'alice';
        $this->assertTrue($this->renderLogin(['enabled' => true, 'hide_login_form' => true])['hide_password_form']);

        unset($_SERVER['REMOTE_USER']);
        $params = $this->renderLogin(['enabled' => true, 'hide_login_form' => true]);
        $this->assertNull($params['remote_user_name']);
        $this->assertFalse($params['hide_password_form'], 'with no web server user the form stays, so nobody is locked out');
    }

    public function testNothingIsOfferedWhileTheFeatureIsOff(): void
    {
        $_SERVER['REMOTE_USER'] = 'alice';

        $params = $this->renderLogin(['enabled' => false, 'hide_login_form' => true]);

        $this->assertNull($params['remote_user_name']);
        $this->assertFalse($params['hide_password_form']);
    }
}
