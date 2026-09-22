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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Auth\OidcConfigurationService;
use Poweradmin\Application\Service\Auth\OidcService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Domain\Service\Auth\PasswordEncryptionService;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use ReflectionMethod;
use Poweradmin\Infrastructure\Session\ArraySession;

class OidcServiceFormPostTest extends TestCase
{
    private ArraySession $session;

    private const SESSION_KEY = 'unit-test-session-key';

    private array $originalCookie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCookie = $_COOKIE;
        $this->session = new ArraySession();
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->originalCookie;
        parent::tearDown();
    }

    private function makeService(string $applicationUrl): OidcService
    {
        $configValues = [
            'interface.application_url' => $applicationUrl,
            'security.session_key' => self::SESSION_KEY,
        ];

        $configManager = $this->createMock(ConfigurationInterface::class);
        $configManager->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => $configValues["$group.$key"] ?? $default
        );

        return new OidcService(
            $configManager,
            $this->createMock(OidcConfigurationService::class),
            $this->createMock(UserProvisioningService::class),
            $this->createMock(Logger::class),
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $this->createMock(MfaService::class),
            $this->session
        );
    }

    private function invoke(OidcService $service, string $method, ...$args)
    {
        $reflection = new ReflectionMethod(OidcService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$args);
    }

    public function testFormPostIsOffByDefault(): void
    {
        $service = $this->makeService('https://dns.example.com');

        $this->assertFalse($this->invoke($service, 'shouldUseFormPost', [], 'test'));
    }

    public function testFormPostIsOffForQueryResponseMode(): void
    {
        $service = $this->makeService('https://dns.example.com');

        $this->assertFalse($this->invoke($service, 'shouldUseFormPost', ['response_mode' => 'query'], 'test'));
    }

    public function testFormPostIsUsedWhenOptedInOverHttps(): void
    {
        $service = $this->makeService('https://dns.example.com');

        $this->assertTrue($this->invoke($service, 'shouldUseFormPost', ['response_mode' => 'form_post'], 'test'));
    }

    public function testFormPostFallsBackToQueryOnPlainHttp(): void
    {
        $service = $this->makeService('http://dns.example.com');

        $this->assertFalse($this->invoke($service, 'shouldUseFormPost', ['response_mode' => 'form_post'], 'test'));
    }

    public function testFormPostIsAllowedOnLocalhostForDevelopment(): void
    {
        $service = $this->makeService('http://localhost:8080');

        $this->assertTrue($this->invoke($service, 'shouldUseFormPost', ['response_mode' => 'form_post'], 'test'));
    }

    public function testFlowCookiePathMatchesSubfolderCallbackUrl(): void
    {
        $service = $this->makeService('https://dns.example.com/poweradmin');

        $this->assertSame('/poweradmin/oidc/callback', $this->invoke($service, 'getFlowCookiePath'));
    }

    public function testRestoreFlowFromCookieHydratesSession(): void
    {
        $service = $this->makeService('https://dns.example.com');
        $encryption = new PasswordEncryptionService(self::SESSION_KEY);
        $_COOKIE['oidc_flow'] = $encryption->encrypt(json_encode([
            'state' => 'state-123',
            'provider' => 'keycloak',
            'verifier' => 'pkce-verifier-456',
        ]));

        $this->invoke($service, 'restoreFlowFromCookie');

        $this->assertSame('state-123', $this->session->get('oidc_state'));
        $this->assertSame('keycloak', $this->session->get('oidc_provider'));
        $this->assertSame('pkce-verifier-456', $this->session->get('oidc_code_verifier'));
    }

    public function testRestoreFlowIgnoresGarbageCookie(): void
    {
        $service = $this->makeService('https://dns.example.com');
        $_COOKIE['oidc_flow'] = 'not-an-encrypted-payload';

        $this->invoke($service, 'restoreFlowFromCookie');

        $session = $this->session->all();
        $this->assertArrayNotHasKey('oidc_state', $session);
        $this->assertArrayNotHasKey('oidc_provider', $session);
        $this->assertArrayNotHasKey('oidc_code_verifier', $session);
    }

    public function testRestoreFlowIgnoresPayloadWithMissingFields(): void
    {
        $service = $this->makeService('https://dns.example.com');
        $encryption = new PasswordEncryptionService(self::SESSION_KEY);
        $_COOKIE['oidc_flow'] = $encryption->encrypt(json_encode([
            'state' => 'state-123',
            'provider' => 'keycloak',
        ]));

        $this->invoke($service, 'restoreFlowFromCookie');

        $session = $this->session->all();
        $this->assertArrayNotHasKey('oidc_state', $session);
        $this->assertArrayNotHasKey('oidc_code_verifier', $session);
    }

    public function testRestoreFlowIgnoresCookieEncryptedWithDifferentKey(): void
    {
        $service = $this->makeService('https://dns.example.com');
        $encryption = new PasswordEncryptionService('some-other-key');
        $_COOKIE['oidc_flow'] = $encryption->encrypt(json_encode([
            'state' => 'state-123',
            'provider' => 'keycloak',
            'verifier' => 'pkce-verifier-456',
        ]));

        $this->invoke($service, 'restoreFlowFromCookie');

        $session = $this->session->all();
        $this->assertArrayNotHasKey('oidc_state', $session);
    }
}
