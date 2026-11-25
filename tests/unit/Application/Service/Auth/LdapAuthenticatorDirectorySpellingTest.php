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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Application\Service\Auth\LdapAuthenticator;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Application\Service\Auth\LoginCredentials;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Domain\Repository\AuthUserLookupInterface;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\NullLogger;

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
    private MockObject&AuditService $audit;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/ldap_function_doubles.php';
    }

    protected function setUp(): void
    {
        $this->attempts = $this->createMock(LoginAttemptService::class);
        $this->audit = $this->createMock(AuditService::class);
        self::$userBindOk = false;
        self::$entries = ['count' => 1, [
            'dn' => 'uid=alice,dc=example',
            'uid' => ['count' => 1, 0 => 'alice'],
        ]];
    }

    protected function tearDown(): void
    {
        self::$entries = null;
    }

    public function testFailureIsCountedForThePostedNamesRowNotTheDirectorySpelling(): void
    {
        $this->attempts->method('userIdFor')->willReturnMap([[' ALICE', 7], ['alice', 9]]);
        $this->attempts->expects($this->once())
            ->method('recordAttempt')
            ->with(' ALICE', '203.0.113.9', false, 'password', 7);

        $outcome = $this->authenticator()->authenticate(new LoginCredentials(' ALICE', 'wrong'));

        $this->assertSame('Authentication failed!', $outcome->message);
    }

    public function testFailureFallsBackToTheDirectorySpellingWhenThePostedNameResolvesToNothing(): void
    {
        $this->attempts->method('userIdFor')->willReturnMap([[' ALICE', null], ['alice', 9]]);
        $this->attempts->expects($this->once())
            ->method('recordAttempt')
            ->with(' ALICE', '203.0.113.9', false, 'password', 9);

        $outcome = $this->authenticator()->authenticate(new LoginCredentials(' ALICE', 'wrong'));

        $this->assertSame('Authentication failed!', $outcome->message);
    }

    public function testLockOnTheRowFoundThroughTheDirectoryRefusesBeforeThePasswordCheck(): void
    {
        $this->attempts->method('userIdFor')->willReturnMap([[' ALICE', null], ['alice', 9]]);
        $this->attempts->method('isAccountLocked')
            ->willReturnCallback(fn(string $name, string $ip, string $type = 'password', ?int $userId = null) => $userId === 9);
        $this->attempts->expects($this->never())->method('recordAttempt');
        $this->audit->expects($this->once())->method('logLoginLocked');
        self::$userBindOk = true;

        $outcome = $this->authenticator()->authenticate(new LoginCredentials(' ALICE', 'right'));

        $this->assertSame('Authentication failed!', $outcome->message);
    }

    public function testARefusedAccountSyncIsAuditedWithItsOwnReasonNotAsADisabledAccount(): void
    {
        $this->attempts->method('userIdFor')->willReturn(7);
        $lookup = $this->createMock(AuthUserLookupInterface::class);
        $lookup->method('findActiveLdapUser')->willReturn(['id' => 7, 'fullname' => 'Alice', 'email' => 'a@example.com']);
        $provisioning = $this->createMock(UserProvisioningService::class);
        $provisioning->method('syncExistingUser')->willReturn(false);
        $this->audit->expects($this->once())->method('logLoginFailed')->with(AuthMethod::LDAP, LoginFailureReason::SYNC_REFUSED);
        self::$userBindOk = true;

        $outcome = $this->authenticator($lookup, $provisioning)->authenticate(new LoginCredentials(' ALICE', 'right'));

        $this->assertSame('LDAP Authentication failed!', $outcome->message);
    }

    private function authenticator(?AuthUserLookupInterface $lookup = null, ?UserProvisioningService $provisioning = null): LdapAuthenticator
    {
        $session = new ArraySession();
        $session->set(SessionKeys::USERLOGIN, ' ALICE');
        $session->set(SessionKeys::USERPWD, 'irrelevant');

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, $default = null) => match ("{$section}.{$key}") {
            'ldap.uri' => 'ldap://double',
            'ldap.bind_dn' => 'cn=svc',
            'ldap.user_attribute' => 'uid',
            'ldap.sync_user_info' => true,
            'security.password_cost' => 4,
            default => $default,
        });

        return new LdapAuthenticator(
            $lookup ?? $this->createMock(AuthUserLookupInterface::class),
            $config,
            $this->audit,
            $this->createMock(CsrfTokenService::class),
            new NullLogger(),
            $this->attempts,
            new UserContextService($session),
            new ClientContext('203.0.113.9', 'phpunit', 'Unknown', false),
            $this->createMock(MfaService::class),
            $provisioning ?? $this->createMock(UserProvisioningService::class),
            $session
        );
    }
}
