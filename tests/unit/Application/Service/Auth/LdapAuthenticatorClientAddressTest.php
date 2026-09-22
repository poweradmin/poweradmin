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

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Application\Service\Auth\LdapAuthenticator;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Application\Service\Auth\AuthOutcomeStatus;
use Poweradmin\Application\Service\Auth\LoginCredentials;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * Pins that the lockout check and the audit line both see the client address
 * the authenticator was handed for the request, not a globally re-read one.
 */
#[CoversClass(LdapAuthenticator::class)]
class LdapAuthenticatorClientAddressTest extends TestCase
{
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
    }

    public function testLockedAccountIsCheckedAndAuditedAgainstTheInjectedClientAddress(): void
    {
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->expects($this->once())->method('isAccountLocked')->with('', '203.0.113.9')->willReturn(true);

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logLoginLocked')->with(AuthMethod::LDAP);

        $authenticator = new LdapAuthenticator(
            $this->createMock(PDO::class),
            new FakeConfiguration(),
            $audit,
            $this->createMock(CsrfTokenService::class),
            new NullLogger(),
            $attempts,
            new UserContextService(),
            new ClientContext('203.0.113.9', 'phpunit', 'Unknown', false),
            $this->createMock(MfaService::class),
            $this->createMock(UserProvisioningService::class)
        );

        $outcome = $authenticator->authenticate(new LoginCredentials('', 'secret'));

        $this->assertSame(AuthOutcomeStatus::Failure, $outcome->status);
    }
}
