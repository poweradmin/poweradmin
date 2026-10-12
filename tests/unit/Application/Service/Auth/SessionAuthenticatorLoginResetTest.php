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

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\SessionAuthenticator;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use TestHelpers\FakeConfiguration;

/**
 * A login form POST starts from an empty session, so nothing of the user the
 * session belonged to before can be promoted by the login that follows.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorLoginResetTest extends TestCase
{
    private const LOGIN_TOKEN = 'login-token';

    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/login';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testALoginPostDropsTheIdentityOfTheUserTheSessionHeldBefore(): void
    {
        $session = $this->sessionOfAnotherUser();

        $this->failLogin($session);

        $this->assertFalse($session->has(SessionKeys::USERID));
        $this->assertFalse($session->has(SessionKeys::PENDING_USERID));
        $this->assertFalse($session->has(SessionKeys::EMAIL));
        $this->assertFalse($session->has(SessionKeys::AUTHENTICATED));
        $this->assertSame('mallory', $session->get(SessionKeys::USERLOGIN));
        $this->assertTrue($session->has(SessionKeys::USERPWD));
    }

    public function testTheSignedOutOfTheWebServerMarkSurvivesAFailedLogin(): void
    {
        $session = $this->sessionOfAnotherUser();
        $session->set(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT, true);

        $this->failLogin($session);

        $this->assertFalse($session->has(SessionKeys::USERID));
        $this->assertTrue($session->get(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT));
    }

    private function sessionOfAnotherUser(): ArraySession
    {
        return new ArraySession([
            SessionKeys::LOGIN_TOKEN => self::LOGIN_TOKEN,
            SessionKeys::USERID => 1,
            SessionKeys::USERLOGIN => 'alice',
            SessionKeys::EMAIL => 'alice@example.com',
            SessionKeys::AUTHENTICATED => true,
            SessionKeys::PENDING_USERID => 1,
        ]);
    }

    /**
     * Posts the login form for an account that does not exist, so the SQL check
     * refuses it and writes no identity of its own.
     */
    private function failLogin(ArraySession $session): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec((string)file_get_contents(dirname(__DIR__, 5) . '/sql/poweradmin-sqlite-db-structure.sql'));

        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'security' => ['session_key' => 'unit-test-session-key'],
        ]);
        $request = new Request([], [
            'authenticate' => '1',
            'username' => 'mallory',
            'password' => 'wrong-password',
            '_token' => self::LOGIN_TOKEN,
        ], ['REQUEST_METHOD' => 'POST']);

        try {
            (new SessionAuthenticator($db, $config, $request, $session))->authenticate();
            $this->fail('A refused login must redirect back to the login page');
        } catch (RequestHalted $halted) {
            $this->assertSame(RequestHalted::KIND_REDIRECT, $halted->kind);
        }
    }
}
