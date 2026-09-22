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
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Auth\AuthOutcome;
use Poweradmin\Application\Service\Auth\AuthOutcomeStatus;
use Poweradmin\Application\Service\Auth\LoginCredentials;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Application\Service\Auth\LdapAuthenticator;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Psr\Log\NullLogger;

/**
 * Pins what LdapAuthenticator decides on the paths that never reach the
 * directory: lockout, missing credentials, and the cached bind result.
 */
#[CoversClass(LdapAuthenticator::class)]
class LdapAuthenticatorOutcomeTest extends TestCase
{
    private array $sessionBackup = [];
    private MockObject&AuditService $audit;
    private MockObject&LoginAttemptService $attempts;

    protected function setUp(): void
    {
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];

        $this->audit = $this->createMock(AuditService::class);
        $this->attempts = $this->createMock(LoginAttemptService::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
    }

    public function testLockedAccountOnTheLoginPostIsRefusedAndAudited(): void
    {
        $_SESSION[SessionKeys::USERLOGIN] = 'alice';
        $this->attempts->method('isAccountLocked')->with('alice', '203.0.113.9')->willReturn(true);
        $this->audit->expects($this->once())->method('logLoginLocked')->with(AuthMethod::LDAP);

        $outcome = $this->authenticator(userRow: null)->authenticate(new LoginCredentials('alice', 'secret'));

        $this->assertFailure($outcome, 'Account is temporarily locked. Please try again later.', endSession: false);
    }

    public function testMissingSessionCredentialsFailWithoutAMessage(): void
    {
        $outcome = $this->authenticator(userRow: null)->authenticate();

        $this->assertFailure($outcome, '', endSession: false);
    }

    public function testValidCachedBindKeepsTheSessionWithoutTouchingTheDirectory(): void
    {
        $this->cacheBind('alice');

        $outcome = $this->authenticator(userRow: ['id' => 7, 'fullname' => 'Alice'])->authenticate();

        $this->assertSame(AuthOutcomeStatus::Success, $outcome->status);
        $this->assertNull($outcome->redirectPath);
        $this->assertSame(7, $_SESSION[SessionKeys::USERID]);
        $this->assertTrue($_SESSION[SessionKeys::AUTHENTICATED]);
    }

    public function testCachedBindForADeactivatedUserEndsTheSession(): void
    {
        $this->cacheBind('alice');

        $outcome = $this->authenticator(userRow: false)->authenticate();

        $this->assertFailure($outcome, 'LDAP Authentication failed!', endSession: true);
        $this->assertArrayNotHasKey(SessionKeys::LDAP_AUTH_TIMESTAMP, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::LDAP_AUTH_USERNAME, $_SESSION);
    }

    private function cacheBind(string $username): void
    {
        $_SESSION[SessionKeys::USERLOGIN] = $username;
        $_SESSION[SessionKeys::USERPWD] = 'irrelevant:ciphertext';
        $_SESSION[SessionKeys::USERID] = 7;
        $_SESSION[SessionKeys::AUTHENTICATED] = true;
        $_SESSION[SessionKeys::LDAP_AUTH_TIMESTAMP] = time();
        $_SESSION[SessionKeys::LDAP_AUTH_USERNAME] = $username;
        $_SESSION[SessionKeys::LDAP_AUTH_IP] = '203.0.113.9';
    }

    private function assertFailure(AuthOutcome $outcome, string $message, bool $endSession): void
    {
        $this->assertSame(AuthOutcomeStatus::Failure, $outcome->status);
        $this->assertSame($message, $outcome->message);
        $this->assertSame($endSession, $outcome->endSession);
        $this->assertNull($outcome->redirectPath);
    }

    /**
     * @param array|false|null $userRow The users-table row the active-status check returns; null when it must not run
     */
    private function authenticator(array|false|null $userRow): LdapAuthenticator
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn($userRow ?? false);
        $db = $this->createMock(PDO::class);
        $db->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $db->expects($userRow === null ? $this->never() : $this->once())->method('prepare')->willReturn($statement);

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, $default = null) => match ("$section.$key") {
            'ldap.session_cache_timeout' => 300,
            default => $default,
        });

        return new LdapAuthenticator(
            $db,
            $config,
            $this->audit,
            $this->createMock(CsrfTokenService::class),
            new NullLogger(),
            $this->attempts,
            new UserContextService(),
            new ClientContext('203.0.113.9', 'phpunit', 'Unknown', false),
            $this->createMock(MfaService::class),
            $this->createMock(UserProvisioningService::class)
        );
    }
}
