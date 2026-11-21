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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Service\SessionAuthenticator;
use ReflectionClass;

/**
 * An SSO session must not outlive the protocol switch or the provider it came from.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorSsoSwitchTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public static function cases(): array
    {
        $oidcOk = ['idp' => ['client_id' => 'id', 'client_secret' => 's']];
        $samlOk = ['idp' => ['enabled' => true]];

        return [
            'oidc on, provider ok' => ['oidc', true, $oidcOk, 'idp', false],
            'oidc switched off' => ['oidc', false, $oidcOk, 'idp', true],
            'oidc switched off, no provider key' => ['oidc', false, $oidcOk, null, true],
            'oidc on, no provider key' => ['oidc', true, [], null, false],
            'oidc provider removed' => ['oidc', true, [], 'idp', true],
            'oidc provider lost its secret' => ['oidc', true, ['idp' => ['client_id' => 'id', 'client_secret' => '']], 'idp', true],
            'oidc provider lost its id' => ['oidc', true, ['idp' => ['client_secret' => 's']], 'idp', true],
            'saml on, provider ok' => ['saml', true, $samlOk, 'idp', false],
            'saml provider without enabled flag' => ['saml', true, ['idp' => []], 'idp', false],
            'saml switched off' => ['saml', false, $samlOk, 'idp', true],
            'saml on, no provider key' => ['saml', true, [], null, false],
            'saml provider removed' => ['saml', true, [], 'idp', true],
            'saml provider enabled 0' => ['saml', true, ['idp' => ['enabled' => 0]], 'idp', true],
            'oidc provider enabled false' => ['oidc', true, ['idp' => ['client_id' => 'id', 'client_secret' => 's', 'enabled' => false]], 'idp', true],
            'oidc provider enabled 0' => ['oidc', true, ['idp' => ['client_id' => 'id', 'client_secret' => 's', 'enabled' => 0]], 'idp', true],
            'saml provider disabled' => ['saml', true, ['idp' => ['enabled' => false]], 'idp', true],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function sessionEndsOnlyWhenTheSwitchOrProviderIsOff(string $protocol, bool $enabled, array $providers, ?string $providerId, bool $ends): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn($section, $key, $default = null) => $section === $protocol
                ? ['enabled' => $enabled, 'providers' => $providers][$key] ?? $default
                : $default
        );

        $authService = $this->createMock(AuthenticationService::class);
        $authService->expects($ends ? $this->once() : $this->never())->method('logout');

        $reflection = new ReflectionClass(SessionAuthenticator::class);
        $authenticator = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('configManager')->setValue($authenticator, $config);
        $reflection->getProperty('authService')->setValue($authenticator, $authService);
        $reflection->getParentClass()->getProperty('logger')->setValue($authenticator, $this->createMock(Logger::class));

        $_SESSION = ['userid' => 5];
        if ($providerId !== null) {
            $_SESSION[$protocol . '_provider'] = $providerId;
        }

        $this->assertSame($ends, $reflection->getMethod('endSessionOfDisabledProvider')->invoke($authenticator, $protocol));
    }

    #[Test]
    #[DataProvider('noAccountSessions')]
    public function aSessionWithoutAnAccountIsLeftAlone(array $session): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn(false);
        $authService = $this->createMock(AuthenticationService::class);
        $authService->expects($this->never())->method('logout');

        $reflection = new ReflectionClass(SessionAuthenticator::class);
        $authenticator = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('configManager')->setValue($authenticator, $config);
        $reflection->getProperty('authService')->setValue($authenticator, $authService);

        $_SESSION = $session;

        $this->assertFalse($reflection->getMethod('endSessionOfDisabledProvider')->invoke($authenticator, 'oidc'));
    }

    public static function noAccountSessions(): array
    {
        return [
            'empty' => [[]],
            'username only' => [['userlogin' => 'alice', 'oidc_provider' => 'idp']],
        ];
    }
}
