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
use Poweradmin\Domain\Repository\AuthUserLookupInterface;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Psr\Log\NullLogger;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * Pins what LdapAuthenticator decides on the paths that never reach the
 * directory: lockout, missing credentials, and the cached bind result.
 */
#[CoversClass(LdapAuthenticator::class)]
class LdapAuthenticatorOutcomeTest extends TestCase
{
    private ArraySession $session;

    private MockObject&AuditService $audit;
    private MockObject&LoginAttemptService $attempts;

    protected function setUp(): void
    {
        $this->session = new ArraySession();

        $this->audit = $this->createMock(AuditService::class);
        $this->attempts = $this->createMock(LoginAttemptService::class);
    }


    public function testLockedAccountOnTheLoginPostIsRefusedAndAudited(): void
    {
        $this->session->set(SessionKeys::USERLOGIN, 'alice');
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
        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
        $this->assertTrue($this->session->get(SessionKeys::AUTHENTICATED));
    }

    public function testCachedBindForADeactivatedUserEndsTheSession(): void
    {
        $this->cacheBind('alice');

        $outcome = $this->authenticator(userRow: false)->authenticate();

        $this->assertFailure($outcome, 'LDAP Authentication failed!', endSession: true);
        $this->assertFalse($this->session->has(SessionKeys::LDAP_AUTH_TIMESTAMP));
        $this->assertFalse($this->session->has(SessionKeys::LDAP_AUTH_USERNAME));
    }

    private function cacheBind(string $username): void
    {
        $this->session->set(SessionKeys::USERLOGIN, $username);
        $this->session->set(SessionKeys::USERPWD, 'irrelevant:ciphertext');
        $this->session->set(SessionKeys::USERID, 7);
        $this->session->set(SessionKeys::AUTHENTICATED, true);
        $this->session->set(SessionKeys::LDAP_AUTH_TIMESTAMP, time());
        $this->session->set(SessionKeys::LDAP_AUTH_USERNAME, $username);
        $this->session->set(SessionKeys::LDAP_AUTH_IP, '203.0.113.9');
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
        $userLookup = $this->createMock(AuthUserLookupInterface::class);
        $userLookup->expects($userRow === null ? $this->never() : $this->once())
            ->method('hasActiveLdapUser')
            ->willReturn((bool)$userRow);

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, $default = null) => match ("$section.$key") {
            'ldap.session_cache_timeout' => 300,
            default => $default,
        });

        return new LdapAuthenticator(
            $userLookup,
            $config,
            $this->audit,
            $this->createMock(CsrfTokenService::class),
            new NullLogger(),
            $this->attempts,
            new UserContextService($this->session),
            new ClientContext('203.0.113.9', 'phpunit', 'Unknown', false),
            $this->createMock(MfaService::class),
            $this->createMock(UserProvisioningService::class),
            $this->session
        );
    }
}
