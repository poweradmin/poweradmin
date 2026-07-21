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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\LogoutController;
use ReflectionClass;

class LogoutControllerTest extends TestCase
{
    private function getLogoutParameterName(string $logoutUrl): string
    {
        $reflection = new ReflectionClass(LogoutController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('getLogoutParameterName');
        $method->setAccessible(true);

        return $method->invoke($controller, $logoutUrl);
    }

    private function buildOidcLogoutUrl(array $providerConfig, string $returnUrl, ?string $idToken): string
    {
        $reflection = new ReflectionClass(LogoutController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildOidcLogoutUrl');
        $method->setAccessible(true);

        return $method->invoke($controller, $providerConfig, $returnUrl, $idToken);
    }

    public function testAuth0UsesReturnToParameter(): void
    {
        $this->assertSame(
            'returnTo',
            $this->getLogoutParameterName('https://example.auth0.com/v2/logout')
        );
    }

    public function testKeycloakUsesPostLogoutRedirectUri(): void
    {
        $this->assertSame(
            'post_logout_redirect_uri',
            $this->getLogoutParameterName('https://sso.example.com/realms/master/protocol/openid-connect/logout')
        );
    }

    public function testAzureUsesPostLogoutRedirectUri(): void
    {
        $this->assertSame(
            'post_logout_redirect_uri',
            $this->getLogoutParameterName('https://login.microsoftonline.com/tenant-id/oauth2/v2.0/logout')
        );
    }

    public function testLogoutUrlAppendsIdTokenHintWhenAvailable(): void
    {
        $url = $this->buildOidcLogoutUrl(
            ['logout_url' => 'https://idp.example.com/logout', 'name' => 'simplesaml'],
            'https://poweradmin.example.com/login',
            'header.payload.signature'
        );

        $this->assertStringContainsString(
            'post_logout_redirect_uri=' . urlencode('https://poweradmin.example.com/login'),
            $url
        );
        $this->assertStringContainsString('&id_token_hint=header.payload.signature', $url);
    }

    public function testLogoutUrlOmitsIdTokenHintWhenNull(): void
    {
        $url = $this->buildOidcLogoutUrl(
            ['logout_url' => 'https://idp.example.com/logout', 'name' => 'simplesaml'],
            'https://poweradmin.example.com/login',
            null
        );

        $this->assertStringNotContainsString('id_token_hint', $url);
    }

    public function testLogoutUrlOmitsIdTokenHintWhenEmptyString(): void
    {
        $url = $this->buildOidcLogoutUrl(
            ['logout_url' => 'https://idp.example.com/logout', 'name' => 'simplesaml'],
            'https://poweradmin.example.com/login',
            ''
        );

        $this->assertStringNotContainsString('id_token_hint', $url);
    }

    public function testLogoutUrlUrlEncodesIdTokenHint(): void
    {
        $url = $this->buildOidcLogoutUrl(
            ['logout_url' => 'https://idp.example.com/logout', 'name' => 'simplesaml'],
            'https://poweradmin.example.com/login',
            'a b+c/d'
        );

        $this->assertStringContainsString('&id_token_hint=' . urlencode('a b+c/d'), $url);
    }

    public function testKeycloakLogoutUrlIncludesClientIdAndIdTokenHint(): void
    {
        $url = $this->buildOidcLogoutUrl(
            [
                'logout_url' => 'https://sso.example.com/realms/master/protocol/openid-connect/logout',
                'name' => 'keycloak',
                'client_id' => 'poweradmin',
            ],
            'https://poweradmin.example.com/login',
            'the-id-token'
        );

        $this->assertStringContainsString('&client_id=poweradmin', $url);
        $this->assertStringContainsString('&id_token_hint=the-id-token', $url);
    }

    public function testAuth0LogoutUrlUsesReturnToAndIncludesIdTokenHint(): void
    {
        $url = $this->buildOidcLogoutUrl(
            ['logout_url' => 'https://example.auth0.com/v2/logout', 'name' => 'auth0'],
            'https://poweradmin.example.com/login',
            'the-id-token'
        );

        $this->assertStringContainsString('returnTo=' . urlencode('https://poweradmin.example.com/login'), $url);
        $this->assertStringNotContainsString('post_logout_redirect_uri', $url);
        $this->assertStringContainsString('&id_token_hint=the-id-token', $url);
    }

    public function testLogoutUrlUsesAmpersandWhenLogoutUrlAlreadyHasQuery(): void
    {
        $url = $this->buildOidcLogoutUrl(
            ['logout_url' => 'https://idp.example.com/logout?foo=bar', 'name' => 'simplesaml'],
            'https://poweradmin.example.com/login',
            'the-id-token'
        );

        $this->assertStringContainsString('?foo=bar&post_logout_redirect_uri=', $url);
    }
}
