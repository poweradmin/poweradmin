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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\SessionAuthenticator;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use TestHelpers\FakeConfiguration;

/**
 * A password login for an account that signs in through an identity provider, or
 * through a disabled LDAP, answers exactly like a wrong password for an SQL account.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorMethodMismatchLoginTest extends TestCase
{
    private const LOGIN_TOKEN = 'login-token';
    private const FAILURE = 'Authentication failed!';

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

    /**
     * @return array<string, array{string}>
     */
    public static function methods(): array
    {
        return ['oidc' => ['oidc'], 'saml' => ['saml'], 'ldap while ldap is disabled' => ['ldap']];
    }

    #[DataProvider('methods')]
    public function testPasswordLoginRedirectsWithTheGenericFailure(string $method): void
    {
        $session = $this->postLogin($method);

        $this->assertSame(self::FAILURE, $session->get(SessionKeys::LOGIN_MESSAGE));
        $this->assertFalse($session->has(SessionKeys::USERID));
    }

    #[DataProvider('methods')]
    public function testPasswordLoginCostsAFullHashCheck(string $method): void
    {
        $start = microtime(true);
        $this->postLogin($method, 11);

        // A skipped hash answers in well under a millisecond; cost 11 takes ~100ms
        $this->assertGreaterThan(0.03, microtime(true) - $start);
    }

    #[DataProvider('ssoMethods')]
    public function testSsoAccountKeepsNoPostedPasswordInTheSession(string $method): void
    {
        $session = $this->postLogin($method);

        $this->assertFalse($session->has(SessionKeys::USERPWD));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ssoMethods(): array
    {
        return ['oidc' => ['oidc'], 'saml' => ['saml']];
    }

    private function postLogin(string $method, int $cost = 4): ArraySession
    {
        $session = new ArraySession([SessionKeys::LOGIN_TOKEN => self::LOGIN_TOKEN]);

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec((string)file_get_contents(dirname(__DIR__, 5) . '/sql/poweradmin-sqlite-db-structure.sql'));
        $db->prepare("INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method) VALUES ('sso', '', 'Sso', 'sso@example.test', '', 1, 1, 0, :m)")
            ->execute([':m' => $method]);

        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'security' => ['session_key' => 'unit-test-session-key', 'password_cost' => $cost],
            'ldap' => ['enabled' => false],
        ]);
        $request = new Request([], [
            'authenticate' => '1',
            'username' => 'sso',
            'password' => 'guess',
            '_token' => self::LOGIN_TOKEN,
        ], ['REQUEST_METHOD' => 'POST']);

        try {
            (new SessionAuthenticator($db, $config, $request, $session))->authenticate();
            $this->fail('A refused login must redirect back to the login page');
        } catch (RequestHalted $halted) {
            $this->assertSame(RequestHalted::KIND_REDIRECT, $halted->kind);
        }

        return $session;
    }
}
