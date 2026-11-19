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


namespace Poweradmin\Tests\Unit\Application\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Service\Auth\ApiKeyActor;
use Poweradmin\Application\Service\Auth\ApiKeyAuthenticationMiddleware;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Application\Service\Auth\BasicAuthenticationMiddleware;
use Poweradmin\Tests\Unit\Application\Controller\Api\V2\V2ControllerTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TestHelpers\FakeConfiguration;

/**
 * A request that authenticates through HTTP Basic is refused when the browser says it came from another site.
 */
#[CoversClass(PublicApiController::class)]
class PublicApiControllerCrossSiteBasicAuthTest extends V2ControllerTestCase
{
    /** @return array<string, array{string}> */
    public static function crossSiteValues(): array
    {
        return [
            'cross-site' => ['cross-site'],
            'same-site' => ['same-site'],
            'unknown value' => ['bogus'],
            'upper case' => ['Cross-Site'],
        ];
    }

    #[DataProvider('crossSiteValues')]
    public function testBasicAuthFromAnotherSiteIsRefusedWithoutAChallenge(string $site): void
    {
        $controller = $this->controller(['HTTP_SEC_FETCH_SITE' => $site], false);

        try {
            $controller->authenticate();
            $this->fail('The request was not refused');
        } catch (RequestHalted $halted) {
            $this->assertSame('403', $halted->target);
        }

        $this->assertSame(403, $controller->sent?->getStatusCode());
        $this->assertFalse($controller->sent->headers->has('WWW-Authenticate'));
        $this->assertSame(0, $this->actorBound);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function allowedHeaders(): array
    {
        return [
            'same-origin' => [['HTTP_SEC_FETCH_SITE' => 'same-origin']],
            'none' => [['HTTP_SEC_FETCH_SITE' => 'none']],
            'absent' => [[]],
        ];
    }

    /** @param array<string, string> $server */
    #[DataProvider('allowedHeaders')]
    public function testBasicAuthIsAllowedWhenTheRequestIsNotCrossSite(array $server): void
    {
        $controller = $this->controller($server, false);

        $controller->authenticate();

        $this->assertNull($controller->sent);
        $this->assertSame(1, $this->actorBound);
    }

    public function testAnApiKeyIsNotAffectedByACrossSiteHeader(): void
    {
        $controller = $this->controller(['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_X_API_KEY' => 'pwa_test'], true);

        $controller->authenticate();

        $this->assertNull($controller->sent);
        $this->assertSame(1, $this->actorBound);
    }

    private int $actorBound = 0;

    /**
     * @param array<string, string> $server
     */
    private function controller(array $server, bool $keyAuthenticates): object
    {
        $keys = $this->createMock(ApiKeyAuthenticationMiddleware::class);
        $keys->method('process')->willReturn($keyAuthenticates);
        $keys->method('getAuthenticatedUserId')->willReturn(7);
        $keys->method('getApiKeyScope')->willReturn(ApiKeyScope::unrestricted());

        $basic = $this->createMock(BasicAuthenticationMiddleware::class);
        $basic->method('getAuthenticatedUserId')->willReturn(7);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('apiKeyAuthenticationMiddleware')->willReturn($keys);
        $factory->method('basicAuthenticationMiddleware')->willReturn($basic);
        $factory->method('bindActor')->willReturnCallback(function (ApiKeyActor $actor): void {
            $this->actorBound++;
        });

        $controller = new class extends PublicApiController {
            public ?Response $sent = null;

            public function __construct()
            {
            }

            public function run(): void
            {
            }

            public function authenticate(): void
            {
                $this->authenticateApiRequest();
            }

            protected function sendAndHalt(Response $response): never
            {
                $this->sent = $response;
                $this->halt(RequestHalted::KIND_RESPONSE, (string) $response->getStatusCode());
            }

            protected function getAuthenticatedUsername(): string
            {
                return 'tester';
            }
        };

        $request = Request::create('/api/v2/zones', 'POST', [], [], [], $server);
        $this->inject($controller, 'request', $request);
        $this->inject($controller, 'config', new FakeConfiguration(['api' => ['enabled' => true, 'basic_auth_enabled' => true]]));
        $this->inject($controller, 'serviceFactory', $factory);

        return $controller;
    }
}
