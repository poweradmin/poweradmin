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
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\SessionAuthenticator;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use TestHelpers\FakeConfiguration;

/**
 * The login POST ends the session, so a user with no users row is routed by the
 * LDAP settings alone: with auto-provisioning on they must reach LDAP.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorLdapAutoProvisionRoutingTest extends TestCase
{
    private const LOGIN_TOKEN = 'login-token';
    private const SQL_FAILURE = 'Authentication failed!';
    private const LDAP_UNREACHABLE = 'Failed to bind to LDAP server!';

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

    #[RequiresPhpExtension('ldap')]
    public function testUnknownUserGoesToLdapWhenAutoProvisioningIsOn(): void
    {
        $session = $this->postLogin(['enabled' => true, 'auto_provision' => true]);

        $this->assertSame(self::LDAP_UNREACHABLE, $session->get(SessionKeys::LOGIN_MESSAGE));
    }

    public function testUnknownUserStaysOnSqlWhenAutoProvisioningIsOff(): void
    {
        $session = $this->postLogin(['enabled' => true, 'auto_provision' => false]);

        $this->assertSame(self::SQL_FAILURE, $session->get(SessionKeys::LOGIN_MESSAGE));
    }

    public function testUnknownUserStaysOnSqlWhenLdapIsDisabled(): void
    {
        $session = $this->postLogin(['enabled' => false, 'auto_provision' => true]);

        $this->assertSame(self::SQL_FAILURE, $session->get(SessionKeys::LOGIN_MESSAGE));
    }

    /**
     * @param array<string, bool> $ldap
     */
    private function postLogin(array $ldap): ArraySession
    {
        $session = new ArraySession([SessionKeys::LOGIN_TOKEN => self::LOGIN_TOKEN]);

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec((string)file_get_contents(dirname(__DIR__, 5) . '/sql/poweradmin-sqlite-db-structure.sql'));

        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'security' => ['session_key' => 'unit-test-session-key'],
            'ldap' => $ldap + ['uri' => 'ldap://127.0.0.1:1', 'base_dn' => 'dc=example,dc=org'],
        ]);
        $request = new Request([], [
            'authenticate' => '1',
            'username' => 'newcomer',
            'password' => 'directory-password',
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
