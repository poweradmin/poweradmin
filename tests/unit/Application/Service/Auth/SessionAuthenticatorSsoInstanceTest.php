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
use Poweradmin\Application\Service\Auth\ExternalSessionGuard;
use Poweradmin\Application\Service\Auth\SessionAuthenticator;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use TestHelpers\FakeConfiguration;

/**
 * An OIDC or SAML session only counts on the installation whose sign-in created it,
 * so a session id carried over from a sibling installation sharing the store is ended.
 */
#[CoversClass(SessionAuthenticator::class)]
#[CoversClass(ExternalSessionGuard::class)]
final class SessionAuthenticatorSsoInstanceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testSessionWithoutInstanceFingerprintIsEnded(): void
    {
        foreach (['oidc', 'saml'] as $method) {
            $session = $this->session($method);

            $this->assertTrue($this->halts($session), $method);
            $this->assertFalse($session->has(SessionKeys::USERID), $method);
            $this->assertSame('Session expired, please login again.', $session->get(SessionKeys::LOGIN_MESSAGE), $method);
        }
    }

    public function testSessionFromAnotherInstanceIsEnded(): void
    {
        $other = ExternalSessionGuard::instanceFingerprint($this->config('other_db'));
        $session = $this->session('oidc', [SessionKeys::SSO_INSTANCE => $other]);

        $this->assertTrue($this->halts($session));
        $this->assertFalse($session->has(SessionKeys::USERID));
        $this->assertSame('Session expired, please login again.', $session->get(SessionKeys::LOGIN_MESSAGE));
    }

    public function testSessionOfThisInstanceContinues(): void
    {
        foreach (['oidc', 'saml'] as $method) {
            $session = $this->session($method, [SessionKeys::SSO_INSTANCE => ExternalSessionGuard::instanceFingerprint($this->config())]);

            $this->assertFalse($this->halts($session), $method);
            $this->assertSame(1, $session->get(SessionKeys::USERID), $method);
        }
    }

    public function testSessionHoldingNoAccountIsLeftToTheLoginFlow(): void
    {
        $session = new ArraySession([SessionKeys::USERLOGIN => 'alice', SessionKeys::LASTMOD => time(), SessionKeys::AUTH_METHOD_USED => 'oidc']);

        $this->assertFalse($this->halts($session));
        $this->assertNull($session->get(SessionKeys::LOGIN_MESSAGE));
    }

    public function testFingerprintDependsOnDatabaseAndSessionKey(): void
    {
        $base = ExternalSessionGuard::instanceFingerprint($this->config());

        $this->assertSame($base, ExternalSessionGuard::instanceFingerprint($this->config()));
        $this->assertNotSame($base, ExternalSessionGuard::instanceFingerprint($this->config('other_db')));
        $this->assertNotSame($base, ExternalSessionGuard::instanceFingerprint($this->config('powerdns', 'another-key')));
        $this->assertNotSame($base, ExternalSessionGuard::instanceFingerprint($this->config('powerdns', 'unit-test-session-key', '/var/lib/other.db')));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function session(string $method, array $extra = []): ArraySession
    {
        return new ArraySession(array_merge([
            SessionKeys::USERID => 1,
            SessionKeys::USERLOGIN => 'alice',
            SessionKeys::LASTMOD => time(),
            SessionKeys::AUTH_METHOD_USED => $method,
        ], $extra));
    }

    private function config(string $dbName = 'powerdns', string $key = 'unit-test-session-key', string $file = ''): FakeConfiguration
    {
        return new FakeConfiguration([
            'database' => ['type' => 'mysql', 'host' => 'db', 'port' => '3306', 'name' => $dbName, 'file' => $file, 'pdns_db_name' => ''],
            'security' => ['session_key' => $key],
            'interface' => ['session_timeout' => 1800, 'session_max_lifetime' => 0],
        ]);
    }

    private function halts(ArraySession $session): bool
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec((string)file_get_contents(dirname(__DIR__, 5) . '/sql/poweradmin-sqlite-db-structure.sql'));
        $db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES (1, 'alice', 'x', 'Alice', 'a@example.com', '', 1, 1, 0)");

        $authenticator = new SessionAuthenticator($db, $this->config(), new Request([], [], ['REQUEST_METHOD' => 'GET']), $session);

        try {
            $authenticator->authenticate();
        } catch (RequestHalted) {
            return true;
        }

        return false;
    }
}
