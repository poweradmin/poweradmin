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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LdapAuthenticator;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\AuthenticationService;
use Psr\Log\NullLogger;

/**
 * Pins that the lockout check and the audit line both see the client address
 * the authenticator was handed for the request, not a globally re-read one.
 */
#[CoversClass(LdapAuthenticator::class)]
class LdapAuthenticatorClientAddressTest extends TestCase
{
    private array $sessionBackup = [];
    private array $postBackup = [];

    protected function setUp(): void
    {
        $this->sessionBackup = $_SESSION ?? [];
        $this->postBackup = $_POST;
        $_SESSION = [];
        $_POST = ['authenticate' => '1'];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        $_POST = $this->postBackup;
    }

    public function testLockedAccountIsCheckedAndAuditedAgainstTheInjectedClientAddress(): void
    {
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->expects($this->once())->method('isAccountLocked')->with('', '203.0.113.9')->willReturn(true);

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logLoginLocked')->with(AuthMethod::LDAP);

        $authentication = $this->createMock(AuthenticationService::class);
        $authentication->expects($this->once())->method('auth');

        $authenticator = new LdapAuthenticator(
            $this->createMock(PDO::class),
            $this->createMock(ConfigurationManager::class),
            $audit,
            $authentication,
            $this->createMock(CsrfTokenService::class),
            new NullLogger(),
            $attempts,
            new UserContextService(),
            new ClientContext('203.0.113.9', 'phpunit', 'Unknown', false),
            $this->createMock(MfaService::class)
        );

        $authenticator->authenticate();
    }
}
