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
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionProperty;

/**
 * The SAML log lines must not carry the PHP session id.
 */
class SamlLogHidesSessionIdTest extends TestCase
{
    private array $server;
    private array $post;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->post = $_POST;
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'pa.example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '443', 'REQUEST_URI' => '/saml/acs'];
        $_POST = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_POST = $this->post;
        $_SESSION = [];
    }

    public function testNoProviderWarningDoesNotLogTheSessionId(): void
    {
        $warnings = [];
        $logger = $this->createMock(Logger::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$warnings): void {
            $warnings[] = [$message, $context];
        });

        $service = new SamlService(
            $this->createMock(ConfigurationManager::class),
            $this->createMock(SamlConfigurationService::class),
            $this->createMock(UserProvisioningService::class),
            $logger,
            $this->createMock(PDO::class),
            new Request()
        );
        $authentication = $this->createMock(AuthenticationService::class);
        $authentication->method('auth')->willReturnCallback(function (SessionEntity $entity): void {
        });
        (new ReflectionProperty(SamlService::class, 'authenticationService'))->setValue($service, $authentication);

        $service->handleAssertion();

        $this->assertNotEmpty($warnings);
        foreach ($warnings as [$message, $context]) {
            $this->assertStringNotContainsString('Session ID', $message);
            $this->assertArrayNotHasKey('session_id', $context);
        }
    }
}
