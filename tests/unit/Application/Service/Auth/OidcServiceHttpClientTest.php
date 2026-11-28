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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Application\Service\Auth\OidcConfigurationService;
use Poweradmin\Application\Service\Auth\OidcService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\NullLogger;
use ReflectionMethod;

class OidcServiceHttpClientTest extends TestCase
{
    public function testIdpClientHasTimeoutsAndRefusesRedirects(): void
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => ['interface.application_url' => 'https://dns.example.com'][$group . '.' . $key] ?? $default
        );
        $oidcConfig = $this->createMock(OidcConfigurationService::class);
        $oidcConfig->method('getProviderConfig')->willReturn([
            'client_id' => 'id',
            'client_secret' => 'secret',
            'authorize_url' => 'https://idp.example.com/authorize',
            'token_url' => 'https://idp.example.com/token',
            'userinfo_url' => 'https://idp.example.com/userinfo',
        ]);
        $service = new OidcService(
            $config,
            $oidcConfig,
            $this->createMock(UserProvisioningService::class),
            new NullLogger(),
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $this->createMock(MfaService::class),
            new ArraySession()
        );

        $provider = (new ReflectionMethod($service, 'createProvider'))->invoke($service, 'test');
        $client = $provider->getHttpClient();

        $this->assertSame(10, $client->getConfig('timeout'));
        $this->assertSame(5, $client->getConfig('connect_timeout'));
        $this->assertFalse($client->getConfig('allow_redirects'));
    }
}
