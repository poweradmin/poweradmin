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

namespace Poweradmin\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\SessionAuthenticator;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use TestHelpers\FakeConfiguration;

/**
 * Posts the login form through SessionAuthenticator against a real LDAP directory:
 * a user the directory does not know and one who gives the wrong password get the
 * same text as a failed SQL login, and a new directory user is auto-provisioned.
 *
 * Skips when no directory is reachable; connection comes from LDAP_TEST_URI,
 * LDAP_TEST_BASE_DN, LDAP_TEST_BIND_DN and LDAP_TEST_BIND_PW (devcontainer defaults).
 */
#[RequiresPhpExtension('ldap')]
class LdapLoginFailureMessageIntegrationTest extends TestCase
{
    private const LOGIN_TOKEN = 'login-token';
    private const SQL_FAILURE = 'Authentication failed!';
    private const UID = 'itest-login';
    private const PASSWORD = 'itest-login-pass';

    /** @var \LDAP\Connection|null */
    private static $ldap = null;
    private static string $uri;
    private static string $baseDn;
    private static string $bindDn;
    private static string $bindPw;

    private PDO $db;

    /** @var array<string, mixed> */
    private array $server;

    public static function setUpBeforeClass(): void
    {
        self::$uri = getenv('LDAP_TEST_URI') ?: 'ldap://localhost:389';
        self::$baseDn = getenv('LDAP_TEST_BASE_DN') ?: 'dc=poweradmin,dc=org';
        self::$bindDn = getenv('LDAP_TEST_BIND_DN') ?: 'cn=admin,dc=poweradmin,dc=org';
        self::$bindPw = getenv('LDAP_TEST_BIND_PW') ?: 'poweradmin';

        $conn = @ldap_connect(self::$uri);
        if (!$conn) {
            self::markTestSkipped('Cannot init LDAP connection to ' . self::$uri);
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 3);
        if (!@ldap_bind($conn, self::$bindDn, self::$bindPw)) {
            self::markTestSkipped('Cannot bind to LDAP at ' . self::$uri);
        }

        self::$ldap = $conn;
        @ldap_add($conn, 'ou=users,' . self::$baseDn, ['objectClass' => ['organizationalUnit'], 'ou' => 'users']);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$ldap !== null) {
            @ldap_delete(self::$ldap, self::userDn());
        }
    }

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/login';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';

        @ldap_delete(self::$ldap, self::userDn());
        ldap_add(self::$ldap, self::userDn(), [
            'objectClass' => ['inetOrgPerson'],
            'uid' => self::UID,
            'cn' => 'Itest Login',
            'sn' => 'Login',
            'mail' => 'itest-login@example.org',
            'userPassword' => self::PASSWORD,
        ]);

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec((string)file_get_contents(__DIR__ . '/../../sql/poweradmin-sqlite-db-structure.sql'));
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testUserMissingFromTheDirectoryGetsTheSqlFailureText(): void
    {
        $session = $this->postLogin('itest-nobody', 'whatever');

        $this->assertSame(self::SQL_FAILURE, $session->get(SessionKeys::LOGIN_MESSAGE));
    }

    public function testWrongDirectoryPasswordGetsTheSqlFailureText(): void
    {
        $session = $this->postLogin(self::UID, 'wrong-password');

        $this->assertSame(self::SQL_FAILURE, $session->get(SessionKeys::LOGIN_MESSAGE));
    }

    public function testNewDirectoryUserIsProvisionedOnFirstLogin(): void
    {
        $session = $this->postLogin(self::UID, self::PASSWORD);

        $this->assertNull($session->get(SessionKeys::LOGIN_MESSAGE));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM users WHERE username = '" . self::UID . "' AND use_ldap = 1")->fetchColumn());
    }

    private static function userDn(): string
    {
        return 'uid=' . self::UID . ',ou=users,' . self::$baseDn;
    }

    private function postLogin(string $username, string $password): ArraySession
    {
        $session = new ArraySession([SessionKeys::LOGIN_TOKEN => self::LOGIN_TOKEN]);

        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'security' => ['session_key' => 'itest-session-key'],
            'ldap' => [
                'enabled' => true,
                'auto_provision' => true,
                'uri' => self::$uri,
                'base_dn' => self::$baseDn,
                'bind_dn' => self::$bindDn,
                'bind_password' => self::$bindPw,
                'user_attribute' => 'uid',
                'default_permission_template' => 'Guest',
            ],
        ]);
        $request = new Request([], [
            'authenticate' => '1',
            'username' => $username,
            'password' => $password,
            '_token' => self::LOGIN_TOKEN,
        ], ['REQUEST_METHOD' => 'POST']);

        // The success path discards one output buffer before redirecting
        $level = ob_get_level();
        ob_start();
        try {
            (new SessionAuthenticator($this->db, $config, $request, $session))->authenticate();
        } catch (RequestHalted) {
            // Both the refusal and the success redirect halt the request
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $session;
    }
}
