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
use Poweradmin\Application\Controller\OidcCallbackController;
use Poweradmin\Application\Service\UserEventLogger;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionClass;
use ReflectionMethod;

/**
 * A posted login name must not plant a client_ip or operation field that the documented
 * fail2ban filter would read, nor break the line.
 */
class UserEventLoggerInjectionTest extends TestCase
{
    private const CRAFTED = "x client_ip:203.0.113.9 operation:login_failed\nforged";

    protected function setUp(): void
    {
        $_SESSION = ['userlogin' => self::CRAFTED];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testSqlSignInLineCarriesTheNameEscaped(): void
    {
        $line = $this->capture(UserEventLogger::class, static fn(UserEventLogger $l) => $l->logFailedAuth());

        $this->assertLineIsSafe($line);
    }

    public function testLdapSignInLineCarriesTheNameEscaped(): void
    {
        $line = $this->capture(LdapUserEventLogger::class, static fn(LdapUserEventLogger $l) => $l->logFailedIncorrectPass());

        $this->assertLineIsSafe($line);
    }

    /** The OIDC callback logs the provider's error parameter, which anyone can send */
    public function testOidcErrorValueIsEscaped(): void
    {
        $value = (new ReflectionMethod(OidcCallbackController::class, 'logValue'))->invoke(null, self::CRAFTED);

        $this->assertSame('x client_ip%3A203.0.113.9 operation%3Alogin_failed?forged', $value);
    }

    private function assertLineIsSafe(string $line): void
    {
        $this->assertStringContainsString('1.2.3.4', $line);
        $this->assertStringNotContainsString('client_ip:203.0.113.9', $line);
        $this->assertStringNotContainsString("\n", $line);
        $this->assertStringContainsString('x%20client_ip%3A203.0.113.9%20operation%3Alogin_failed?forged', $line);
    }

    /**
     * @param class-string $class
     */
    private function capture(string $class, callable $action): string
    {
        $reflection = new ReflectionClass($class);
        $logger = $reflection->newInstanceWithoutConstructor();
        $captor = new class extends LegacyLogger {
            public string $line = '';

            public function __construct()
            {
                // The captor never touches the database
            }

            public function logError(string $message, ?int $zone_id = null): void
            {
                $this->line = $message;
            }

            public function logWarn(string $message, ?int $zone_id = null): void
            {
                $this->line = $message;
            }

            public function logNotice(string $message): void
            {
                $this->line = $message;
            }
        };
        $reflection->getProperty('logger')->setValue($logger, $captor);
        $reflection->getProperty('ipRetriever')->setValue($logger, new IpAddressRetriever(['REMOTE_ADDR' => '1.2.3.4']));

        $action($logger);

        return $captor->line;
    }
}
