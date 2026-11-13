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

class SamlDisabledProviderAssertionTest extends TestCase
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

    public function testAnAssertionForADisabledProviderIsRefusedBeforeAuthIsBuilt(): void
    {
        $_SESSION['saml_provider'] = 'idp';

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $group === 'saml' && $key === 'providers'
                ? ['idp' => ['enabled' => false, 'entity_id' => 'https://idp.example.com', 'sso_url' => 'https://idp.example.com/sso']]
                : $default
        );
        $samlConfig = $this->createMock(SamlConfigurationService::class);
        $samlConfig->expects($this->never())->method('generateOneLoginSettings');

        $service = new SamlService(
            $config,
            $samlConfig,
            $this->createMock(UserProvisioningService::class),
            $this->createMock(Logger::class),
            $this->createMock(PDO::class),
            new Request()
        );
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects($this->once())->method('auth');
        (new ReflectionProperty(SamlService::class, 'authenticationService'))->setValue($service, $auth);

        $service->handleAssertion();

        $this->assertArrayNotHasKey('saml_provider', $_SESSION);
    }
}
