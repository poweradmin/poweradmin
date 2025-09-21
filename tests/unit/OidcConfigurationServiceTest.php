<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\OidcConfigurationService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;

class OidcConfigurationServiceTest extends TestCase
{
    private ConfigurationManager $configManager;
    private Logger $logger;
    private OidcConfigurationService $service;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigurationManager::class);
        $this->logger = $this->createMock(Logger::class);
        $this->service = new OidcConfigurationService($this->configManager, $this->logger);
    }

    public function testUrlTemplatingWithTenantPlaceholder(): void
    {
        $providerConfig = [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'tenant' => '60f10e4d-114c-4e09-8000-aed017edafb6',
            'auto_discovery' => false, // Disable auto discovery to test manual URL processing
            'metadata_url' => 'https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration',
            'authorize_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
        ];

        $this->configManager
            ->expects($this->once())
            ->method('get')
            ->with('oidc', 'providers', [])
            ->willReturn(['azure' => $providerConfig]);

        $result = $this->service->getProviderConfig('azure');

        // This test currently FAILS because templating is not implemented
        // The URLs should have {tenant} replaced with actual tenant ID
        $this->assertNotNull($result);
        $this->assertEquals(
            'https://login.microsoftonline.com/60f10e4d-114c-4e09-8000-aed017edafb6/v2.0/.well-known/openid-configuration',
            $result['metadata_url'],
            'metadata_url should have {tenant} placeholder replaced with actual tenant ID'
        );
        $this->assertEquals(
            'https://login.microsoftonline.com/60f10e4d-114c-4e09-8000-aed017edafb6/oauth2/v2.0/authorize',
            $result['authorize_url'],
            'authorize_url should have {tenant} placeholder replaced with actual tenant ID'
        );
        $this->assertEquals(
            'https://login.microsoftonline.com/60f10e4d-114c-4e09-8000-aed017edafb6/oauth2/v2.0/token',
            $result['token_url'],
            'token_url should have {tenant} placeholder replaced with actual tenant ID'
        );
    }

    public function testUrlTemplatingWithKeycloakPlaceholders(): void
    {
        $providerConfig = [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'base_url' => 'https://keycloak.example.com',
            'realm' => 'poweradmin',
            'auto_discovery' => false,
            'metadata_url' => '{base_url}/auth/realms/{realm}/.well-known/openid-configuration',
            'authorize_url' => '{base_url}/auth/realms/{realm}/protocol/openid-connect/auth',
            'token_url' => '{base_url}/auth/realms/{realm}/protocol/openid-connect/token',
            'userinfo_url' => '{base_url}/auth/realms/{realm}/protocol/openid-connect/userinfo',
        ];

        $this->configManager
            ->expects($this->once())
            ->method('get')
            ->with('oidc', 'providers', [])
            ->willReturn(['keycloak' => $providerConfig]);

        $result = $this->service->getProviderConfig('keycloak');

        // This test currently FAILS because templating is not implemented
        $this->assertNotNull($result);
        $this->assertEquals(
            'https://keycloak.example.com/auth/realms/poweradmin/.well-known/openid-configuration',
            $result['metadata_url'],
            'metadata_url should have placeholders replaced'
        );
        $this->assertEquals(
            'https://keycloak.example.com/auth/realms/poweradmin/protocol/openid-connect/auth',
            $result['authorize_url'],
            'authorize_url should have placeholders replaced'
        );
    }

    public function testUrlTemplatingWithOktaPlaceholders(): void
    {
        $providerConfig = [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'domain' => 'dev-123456.okta.com',
            'auto_discovery' => false,
            'metadata_url' => 'https://{domain}/.well-known/openid-configuration',
            'authorize_url' => 'https://{domain}/oauth2/v1/authorize',
            'token_url' => 'https://{domain}/oauth2/v1/token',
            'userinfo_url' => 'https://{domain}/oauth2/v1/userinfo',
        ];

        $this->configManager
            ->expects($this->once())
            ->method('get')
            ->with('oidc', 'providers', [])
            ->willReturn(['okta' => $providerConfig]);

        $result = $this->service->getProviderConfig('okta');

        // This test currently FAILS because templating is not implemented
        $this->assertNotNull($result);
        $this->assertEquals(
            'https://dev-123456.okta.com/.well-known/openid-configuration',
            $result['metadata_url'],
            'metadata_url should have {domain} placeholder replaced'
        );
    }

    public function testNoTemplatingWithRegularUrls(): void
    {
        $providerConfig = [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'auto_discovery' => false,
            'metadata_url' => 'https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration',
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
        ];

        $this->configManager
            ->expects($this->once())
            ->method('get')
            ->with('oidc', 'providers', [])
            ->willReturn(['azure' => $providerConfig]);

        $result = $this->service->getProviderConfig('azure');

        // This test should PASS - URLs without placeholders should remain unchanged
        $this->assertNotNull($result);
        $this->assertEquals(
            'https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration',
            $result['metadata_url'],
            'Regular URLs without placeholders should remain unchanged'
        );
    }

    public function testComprehensiveUrlTemplatingWithAllFields(): void
    {
        $providerConfig = [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'tenant' => '60f10e4d-114c-4e09-8000-aed017edafb6',
            'auto_discovery' => false,
            'metadata_url' => 'https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration',
            'authorize_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
            'logout_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/logout',
        ];

        $this->configManager
            ->expects($this->once())
            ->method('get')
            ->with('oidc', 'providers', [])
            ->willReturn(['azure' => $providerConfig]);

        $result = $this->service->getProviderConfig('azure');

        $this->assertNotNull($result);

        // Test all URL fields are properly templated
        $expectedTenant = '60f10e4d-114c-4e09-8000-aed017edafb6';
        $this->assertEquals(
            "https://login.microsoftonline.com/{$expectedTenant}/v2.0/.well-known/openid-configuration",
            $result['metadata_url']
        );
        $this->assertEquals(
            "https://login.microsoftonline.com/{$expectedTenant}/oauth2/v2.0/authorize",
            $result['authorize_url']
        );
        $this->assertEquals(
            "https://login.microsoftonline.com/{$expectedTenant}/oauth2/v2.0/token",
            $result['token_url']
        );
        $this->assertEquals(
            "https://login.microsoftonline.com/{$expectedTenant}/oauth2/v2.0/logout",
            $result['logout_url']
        );
        // userinfo_url should remain unchanged (no placeholders)
        $this->assertEquals(
            'https://graph.microsoft.com/oidc/userinfo',
            $result['userinfo_url']
        );
    }

    public function testAuthentikCompleteConfiguration(): void
    {
        $providerConfig = [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'base_url' => 'https://authentik.example.com',
            'application_slug' => 'poweradmin-app',
            'auto_discovery' => false,
            'metadata_url' => '{base_url}/application/o/{application_slug}/.well-known/openid-configuration',
            'authorize_url' => '{base_url}/application/o/{application_slug}/authorize/',
            'token_url' => '{base_url}/application/o/{application_slug}/token/',
            'userinfo_url' => '{base_url}/application/o/{application_slug}/userinfo/',
            'logout_url' => '{base_url}/application/o/{application_slug}/end-session/',
        ];

        $this->configManager
            ->expects($this->once())
            ->method('get')
            ->with('oidc', 'providers', [])
            ->willReturn(['authentik' => $providerConfig]);

        $result = $this->service->getProviderConfig('authentik');

        $this->assertNotNull($result);

        // Test all URL fields with multiple placeholders
        $baseUrl = 'https://authentik.example.com';
        $slug = 'poweradmin-app';
        $this->assertEquals(
            "{$baseUrl}/application/o/{$slug}/.well-known/openid-configuration",
            $result['metadata_url']
        );
        $this->assertEquals(
            "{$baseUrl}/application/o/{$slug}/authorize/",
            $result['authorize_url']
        );
        $this->assertEquals(
            "{$baseUrl}/application/o/{$slug}/token/",
            $result['token_url']
        );
        $this->assertEquals(
            "{$baseUrl}/application/o/{$slug}/userinfo/",
            $result['userinfo_url']
        );
        $this->assertEquals(
            "{$baseUrl}/application/o/{$slug}/end-session/",
            $result['logout_url']
        );
    }

    public function testMissingRequiredFields(): void
    {
        $providerConfig = [
            'tenant' => '60f10e4d-114c-4e09-8000-aed017edafb6',
            'metadata_url' => 'https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration',
            // Missing client_id and client_secret
        ];

        $this->configManager
            ->expects($this->once())
            ->method('get')
            ->with('oidc', 'providers', [])
            ->willReturn(['azure' => $providerConfig]);

        $result = $this->service->getProviderConfig('azure');

        $this->assertNull($result, 'Should return null when required fields are missing');
    }
}
