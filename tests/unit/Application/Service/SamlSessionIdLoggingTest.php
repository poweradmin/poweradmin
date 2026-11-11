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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Application\Service\SamlService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionProperty;

class SamlSessionIdLoggingTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'pa.example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '443', 'REQUEST_URI' => '/saml/acs'];
        $_COOKIE = [];
        $_POST = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_SESSION = [];
    }

    public function testTheNoProviderWarningDoesNotCarryTheSessionId(): void
    {
        $contexts = [];
        $logger = $this->createMock(Logger::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$contexts): void {
            $contexts[] = $message . ' ' . json_encode($context);
        });

        $service = new SamlService(
            $this->createMock(ConfigurationManager::class),
            $this->createMock(SamlConfigurationService::class),
            $this->createMock(UserProvisioningService::class),
            $logger,
            $this->createMock(PDO::class),
            new Request()
        );
        (new ReflectionProperty(SamlService::class, 'authenticationService'))
            ->setValue($service, $this->createMock(AuthenticationService::class));

        $service->handleAssertion();

        $this->assertNotEmpty($contexts);
        foreach ($contexts as $line) {
            $this->assertDoesNotMatchRegularExpression('/session[ _]id/i', $line);
        }
    }
}
