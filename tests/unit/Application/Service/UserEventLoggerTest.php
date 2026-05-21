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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UserEventLogger;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionClass;

/**
 * Verifies the fail2ban-friendly log line shape emitted by UserEventLogger
 * and LdapUserEventLogger. The format is load-bearing for the documented
 * fail2ban filter, so any deviation needs to be deliberate.
 */
class UserEventLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_SESSION['userlogin'] = 'alice';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testFailedAuthFormatWithoutReason(): void
    {
        $captured = $this->captureUserEventLog(static function (UserEventLogger $logger): void {
            $logger->logFailedAuth();
        });

        $this->assertSame(
            'client_ip:1.2.3.4 user:alice operation:login_failed auth_method:sql',
            $captured['message']
        );
        $this->assertSame(LOG_WARNING, $captured['priority']);
    }

    public function testFailedAuthFormatWithReason(): void
    {
        $captured = $this->captureUserEventLog(static function (UserEventLogger $logger): void {
            $logger->logFailedAuth(AuthMethod::SQL, LoginFailureReason::WRONG_PASSWORD);
        });

        $this->assertSame(
            'client_ip:1.2.3.4 user:alice operation:login_failed auth_method:sql reason:wrong_password',
            $captured['message']
        );
    }

    public function testFailedAuthCarriesAuthMethod(): void
    {
        $captured = $this->captureUserEventLog(static function (UserEventLogger $logger): void {
            $logger->logFailedAuth(AuthMethod::OIDC, LoginFailureReason::NO_SUCH_USER);
        });

        $this->assertStringContainsString('auth_method:oidc', $captured['message']);
        $this->assertStringContainsString('reason:no_such_user', $captured['message']);
    }

    public function testFailedAuthWhenUserloginUnset(): void
    {
        unset($_SESSION['userlogin']);

        $captured = $this->captureUserEventLog(static function (UserEventLogger $logger): void {
            $logger->logFailedAuth(AuthMethod::SQL, LoginFailureReason::NO_SUCH_USER);
        });

        $this->assertSame(
            'client_ip:1.2.3.4 user: operation:login_failed auth_method:sql reason:no_such_user',
            $captured['message']
        );
    }

    public function testLockoutEmitsStructuredLine(): void
    {
        $captured = $this->captureUserEventLog(static function (UserEventLogger $logger): void {
            $logger->logLockout();
        });

        $this->assertSame(
            'client_ip:1.2.3.4 user:alice operation:login_locked auth_method:sql',
            $captured['message']
        );
        $this->assertSame(LOG_WARNING, $captured['priority']);
    }

    public function testLdapLockoutCarriesLdapAuthMethod(): void
    {
        $captured = $this->captureLdapUserEventLog(static function (LdapUserEventLogger $logger): void {
            $logger->logLockout();
        });

        $this->assertSame(
            'client_ip:1.2.3.4 user:alice operation:login_locked auth_method:ldap',
            $captured['message']
        );
    }

    public function testLdapFailedAuthMatchesUnifiedShape(): void
    {
        $captured = $this->captureLdapUserEventLog(static function (LdapUserEventLogger $logger): void {
            $logger->logFailedIncorrectPass();
        });

        $this->assertSame(
            'client_ip:1.2.3.4 user:alice operation:login_failed auth_method:ldap reason:wrong_password',
            $captured['message']
        );
    }

    /**
     * Backend errors (LDAP server unreachable, search failure) must NOT use
     * operation:login_failed — otherwise fail2ban would ban legitimate users
     * during an LDAP outage. They use operation:login_error instead.
     */
    public function testLdapBackendErrorUsesDistinctOperation(): void
    {
        $captured = $this->captureLdapUserEventLog(static function (LdapUserEventLogger $logger): void {
            $logger->logFailedReason('ldap_search');
        });

        $this->assertSame(
            'client_ip:1.2.3.4 user:alice operation:login_error auth_method:ldap reason:ldap_search_failed',
            $captured['message']
        );
        $this->assertStringNotContainsString('operation:login_failed', $captured['message']);
    }

    private function captureUserEventLog(callable $action): array
    {
        $reflection = new ReflectionClass(UserEventLogger::class);
        /** @var UserEventLogger $logger */
        $logger = $reflection->newInstanceWithoutConstructor();

        $captured = [];
        $captor = $this->makeLoggerCaptor($captured);

        $reflection->getProperty('logger')->setValue($logger, $captor);
        $reflection->getProperty('ipRetriever')->setValue($logger, $this->makeIpRetriever());

        $action($logger);

        return $captured;
    }

    private function captureLdapUserEventLog(callable $action): array
    {
        $reflection = new ReflectionClass(LdapUserEventLogger::class);
        /** @var LdapUserEventLogger $logger */
        $logger = $reflection->newInstanceWithoutConstructor();

        $captured = [];
        $captor = $this->makeLoggerCaptor($captured);

        $reflection->getProperty('logger')->setValue($logger, $captor);
        $reflection->getProperty('ipRetriever')->setValue($logger, $this->makeIpRetriever());

        $action($logger);

        return $captured;
    }

    /**
     * @param array<string, mixed> $captured
     */
    private function makeLoggerCaptor(array &$captured): LegacyLogger
    {
        return new class($captured) extends LegacyLogger {
            /** @var array<string, mixed> */
            private array $sink;

            /**
             * @param array<string, mixed> $sink
             */
            public function __construct(array &$sink)
            {
                // bypass parent constructor — captor never touches DB or config
                $this->sink = &$sink;
            }

            public function logError(string $message, ?int $zone_id = null): void
            {
                $this->sink = ['message' => $message, 'priority' => LOG_ERR];
            }

            public function logWarn(string $message, ?int $zone_id = null): void
            {
                $this->sink = ['message' => $message, 'priority' => LOG_WARNING];
            }

            public function logNotice(string $message): void
            {
                $this->sink = ['message' => $message, 'priority' => LOG_NOTICE];
            }

            public function logInfo(string $message, ?int $zone_id = null): void
            {
                $this->sink = ['message' => $message, 'priority' => LOG_INFO];
            }
        };
    }

    private function makeIpRetriever(): IpAddressRetriever
    {
        return new IpAddressRetriever(['REMOTE_ADDR' => '1.2.3.4']);
    }
}
