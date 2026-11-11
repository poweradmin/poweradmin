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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\OidcCallbackController;
use Poweradmin\Application\Controller\OidcLoginController;
use Poweradmin\Application\Controller\SamlCallbackController;
use Poweradmin\Application\Controller\SamlLoginController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\OidcService;
use Poweradmin\Application\Service\SamlService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use ReflectionClass;

/**
 * Text from the query string or from an exception is never shown on the login page.
 */
class SsoFailureMessageTest extends TestCase
{
    private const GENERIC = 'Authentication failed!';

    private ?SessionEntity $shown = null;
    private string $savedUri = '';

    protected function setUp(): void
    {
        $this->savedUri = $_SERVER['REQUEST_URI'] ?? '';
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_URI'] = $this->savedUri;
    }

    public function testOidcCallbackShowsAFixedMessageForAProviderError(): void
    {
        $oidc = $this->createMock(OidcService::class);
        $oidc->method('isEnabled')->willReturn(true);
        $request = $this->query(['error' => 'access_denied', 'error_description' => 'Visit evil.example to continue']);
        $controller = $this->wire(OidcCallbackController::class, [
            'oidcService' => $oidc,
            'httpRequest' => $request,
        ]);

        $controller->run();

        $this->assertSame(self::GENERIC, $this->shown?->getMessage());
    }

    public function testOidcLoginShowsAFixedMessageWhenTheFlowCannotStart(): void
    {
        $oidc = $this->createMock(OidcService::class);
        $oidc->method('isEnabled')->willReturn(true);
        $oidc->method('initiateAuthFlow')->willThrowException(new \RuntimeException('secret endpoint http://10.0.0.5/idp'));
        $controller = $this->wire(OidcLoginController::class, [
            'oidcService' => $oidc,
            'httpRequest' => $this->query(['provider' => 'azure']),
        ]);

        $controller->run();

        $this->assertSame(self::GENERIC, $this->shown?->getMessage());
    }

    public function testSamlLoginShowsAFixedMessageWhenTheFlowCannotStart(): void
    {
        $saml = $this->createMock(SamlService::class);
        $saml->method('isEnabled')->willReturn(true);
        $saml->method('getAvailableProviders')->willReturn(['okta' => []]);
        $saml->method('initiateAuthFlow')->willThrowException(new \RuntimeException('secret endpoint http://10.0.0.5/idp'));
        $controller = $this->wire(SamlLoginController::class, [
            'samlService' => $saml,
            'httpRequest' => $this->query(['provider' => 'okta']),
        ]);

        $controller->run();

        $this->assertSame(self::GENERIC, $this->shown?->getMessage());
    }

    public function testSamlCallbackShowsAFixedMessageWhenTheAssertionFails(): void
    {
        $_SERVER['REQUEST_URI'] = '/saml/acs';
        $saml = $this->createMock(SamlService::class);
        $saml->method('isEnabled')->willReturn(true);
        $saml->method('handleAssertion')->willThrowException(new \RuntimeException('secret endpoint http://10.0.0.5/idp'));
        $controller = $this->wire(SamlCallbackController::class, [
            'samlService' => $saml,
        ]);

        $controller->run();

        $this->assertSame(self::GENERIC, $this->shown?->getMessage());
    }

    public function testSamlLogoutWarningDoesNotCarryTheExceptionText(): void
    {
        $_SERVER['REQUEST_URI'] = '/saml/sls';
        $saml = $this->createMock(SamlService::class);
        $saml->method('isEnabled')->willReturn(true);
        $saml->method('handleSingleLogout')->willThrowException(new \RuntimeException('secret endpoint http://10.0.0.5/idp'));
        $controller = $this->wire(SamlCallbackController::class, [
            'samlService' => $saml,
        ]);

        $controller->run();

        $this->assertStringNotContainsString('secret endpoint', (string)$this->shown?->getMessage());
    }

    /**
     * @param array<string, string> $params
     */
    private function query(array $params): Request&MockObject
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
        );

        return $request;
    }

    /**
     * @param class-string<BaseController> $class
     * @param array<string, mixed> $properties
     */
    private function wire(string $class, array $properties): BaseController
    {
        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        $auth = $this->createMock(AuthenticationService::class);
        $capture = function (SessionEntity $entity): void {
            $this->shown = $entity;
        };
        $auth->method('auth')->willReturnCallback($capture);
        $auth->method('logout')->willReturnCallback($capture);

        $properties['authService'] = $auth;
        foreach ($properties as $name => $value) {
            (new ReflectionClass($class))->getProperty($name)->setValue($controller, $value);
        }

        return $controller;
    }
}
