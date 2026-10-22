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
use Poweradmin\Application\Service\LdapAuthenticator;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\Infrastructure\Logger\Logger;

/**
 * A login for a user the directory does not know and one with the wrong password
 * show the same text as a failed SQL login, so the form does not reveal which
 * usernames exist in the directory.
 *
 * Skips when no directory is reachable; connection comes from LDAP_TEST_URI,
 * LDAP_TEST_BASE_DN, LDAP_TEST_BIND_DN and LDAP_TEST_BIND_PW (devcontainer defaults).
 */
#[RequiresPhpExtension('ldap')]
class LdapLoginFailureMessageIntegrationTest extends TestCase
{
    private const SQL_FAILURE = 'Authentication failed!';
    private const SESSION_KEY = 'itest-session-key';
    private const UID = 'itest-login';
    private const PASSWORD = 'itest-login-pass';

    /** @var \LDAP\Connection|null */
    private static $ldap = null;
    private static string $uri;
    private static string $baseDn;
    private static string $bindDn;
    private static string $bindPw;

    /** @var array<string, mixed> */
    private array $post;

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
        $this->post = $_POST;
        $_POST = ['authenticate' => '1'];

        @ldap_delete(self::$ldap, self::userDn());
        ldap_add(self::$ldap, self::userDn(), [
            'objectClass' => ['inetOrgPerson'],
            'uid' => self::UID,
            'cn' => 'Itest Login',
            'sn' => 'Login',
            'userPassword' => self::PASSWORD,
        ]);
    }

    protected function tearDown(): void
    {
        $_POST = $this->post;
        $_SESSION = [];
    }

    public function testUserMissingFromTheDirectoryGetsTheSqlFailureText(): void
    {
        $this->assertSame(self::SQL_FAILURE, $this->failureMessage('itest-nobody', 'whatever'));
    }

    public function testWrongDirectoryPasswordGetsTheSqlFailureText(): void
    {
        $this->assertSame(self::SQL_FAILURE, $this->failureMessage(self::UID, 'wrong-password'));
    }

    private static function userDn(): string
    {
        return 'uid=' . self::UID . ',ou=users,' . self::$baseDn;
    }

    private function failureMessage(string $username, string $password): string
    {
        $_SESSION = [
            SessionKeys::USERLOGIN => $username,
            SessionKeys::USERPWD => (new PasswordEncryptionService(self::SESSION_KEY))->encrypt($password),
        ];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($section, $key, $default = null) {
            $values = [
                'security' => ['session_key' => self::SESSION_KEY],
                'ldap' => [
                    'uri' => self::$uri,
                    'base_dn' => self::$baseDn,
                    'bind_dn' => self::$bindDn,
                    'bind_password' => self::$bindPw,
                    'user_attribute' => 'uid',
                    'session_cache_timeout' => 0,
                ],
            ];
            return $values[$section][$key] ?? $default;
        });

        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(false);

        $messages = [];
        $capture = function (SessionEntity $entity) use (&$messages): void {
            $messages[] = $entity->getMessage();
        };
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('logout')->willReturnCallback($capture);
        $auth->method('auth')->willReturnCallback($capture);

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        (new LdapAuthenticator(
            $db,
            $config,
            $this->createMock(LdapUserEventLogger::class),
            $auth,
            new CsrfTokenService(),
            $this->createMock(Logger::class),
            $attempts,
            new UserContextService(),
            ['REMOTE_ADDR' => '192.0.2.10']
        ))->authenticate();

        $this->assertCount(1, $messages);

        return $messages[0];
    }
}
