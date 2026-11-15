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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LdapAuthenticator;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\Infrastructure\Logger\Logger;

/**
 * Lockout counts go to the account the posted name resolves to, falling back to the
 * directory's spelling, so a posted variant (leading space, other case) is still counted.
 */
#[CoversClass(LdapAuthenticator::class)]
#[RequiresPhpExtension('ldap')]
class LdapAuthenticatorDirectorySpellingTest extends TestCase
{
    /** Read by the ldap_* doubles in ldap_function_doubles.php; null means call the real extension. */
    public static ?array $entries = null;
    public static bool $userBindOk = false;

    private MockObject&LoginAttemptService $attempts;
    private MockObject&LdapUserEventLogger $events;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/ldap_function_doubles.php';
    }

    protected function setUp(): void
    {
        $this->attempts = $this->createMock(LoginAttemptService::class);
        $this->events = $this->createMock(LdapUserEventLogger::class);
        self::$userBindOk = false;
        self::$entries = ['count' => 1, [
            'dn' => 'uid=alice,dc=example',
            'uid' => ['count' => 1, 0 => 'alice'],
        ]];
        $_SESSION = [
            'userlogin' => ' ALICE',
            'userpwd' => (new PasswordEncryptionService('k'))->encrypt('secret'),
        ];
    }

    protected function tearDown(): void
    {
        self::$entries = null;
        $_SESSION = [];
        unset($_POST["authenticate"]);
    }

    public function testFailureIsCountedForThePostedNamesRowNotTheDirectorySpelling(): void
    {
        $_POST['authenticate'] = '1';
        $this->attempts->method('userIdFor')->willReturnMap([[' ALICE', 7], ['alice', 9]]);
        $this->attempts->expects($this->once())
            ->method('recordAttempt')
            ->with(' ALICE', '203.0.113.9', false, 'password', 7);

        $this->authenticator()->authenticate();
    }

    public function testFailureFallsBackToTheDirectorySpellingWhenThePostedNameResolvesToNothing(): void
    {
        $_POST['authenticate'] = '1';
        $this->attempts->method('userIdFor')->willReturnMap([[' ALICE', null], ['alice', 9]]);
        $this->attempts->expects($this->once())
            ->method('recordAttempt')
            ->with(' ALICE', '203.0.113.9', false, 'password', 9);

        $this->authenticator()->authenticate();
    }

    public function testLockOnTheRowFoundThroughTheDirectoryRefusesBeforeThePasswordCheck(): void
    {
        $_POST['authenticate'] = '1';
        $this->attempts->method('userIdFor')->willReturnMap([[' ALICE', null], ['alice', 9]]);
        $this->attempts->method('isAccountLocked')
            ->willReturnCallback(fn(string $name, string $ip, string $type = 'password', ?int $userId = null) => $userId === 9);
        $this->attempts->expects($this->never())->method('recordAttempt');
        $this->events->expects($this->once())->method('logLockout');
        self::$userBindOk = true;

        $this->authenticator()->authenticate();

        $this->assertArrayNotHasKey('userid', $_SESSION);
    }

    private function authenticator(): LdapAuthenticator
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, $default = null) => match ("{$section}.{$key}") {
            'ldap.uri' => 'ldap://double',
            'ldap.bind_dn' => 'cn=svc',
            'ldap.user_attribute' => 'uid',
            'security.session_key' => 'k',
            'security.password_cost' => 4,
            default => $default,
        });

        return new LdapAuthenticator(
            new PDO('sqlite::memory:'),
            $config,
            $this->events,
            $this->createMock(AuthenticationService::class),
            $this->createMock(CsrfTokenService::class),
            $this->createMock(Logger::class),
            $this->attempts,
            new UserContextService(),
            ['REMOTE_ADDR' => '203.0.113.9']
        );
    }
}
