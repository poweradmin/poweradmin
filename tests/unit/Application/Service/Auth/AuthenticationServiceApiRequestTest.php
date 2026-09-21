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
 */

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Application\Web\FlashMessage;
use Poweradmin\Infrastructure\Service\RedirectService;
use Poweradmin\Infrastructure\Session\SessionService;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

/**
 * An unauthenticated internal-API request must be answered with a 401 JSON body
 * instead of a 302 redirect to the HTML login page (audit M17). The branch is
 * chosen by isApiRequest(), driven by the request URI.
 */
class AuthenticationServiceApiRequestTest extends TestCase
{
    private function isApiRequest(string $requestUri): bool
    {
        $service = new AuthenticationService(
            $this->createMock(SessionService::class),
            $this->createMock(RedirectService::class),
            $this->createMock(ConfigurationInterface::class)
        );
        $_SERVER['REQUEST_URI'] = $requestUri;
        $method = new ReflectionMethod(AuthenticationService::class, 'isApiRequest');
        $method->setAccessible(true);
        return $method->invoke($service);
    }

    public function testInternalApiRouteIsDetected(): void
    {
        $this->assertTrue($this->isApiRequest('/api/internal/zone'));
    }

    public function testPublicApiRouteIsDetected(): void
    {
        $this->assertTrue($this->isApiRequest('/api/v2/zones/1/records'));
    }

    public function testSubfolderApiRouteIsDetected(): void
    {
        $this->assertTrue($this->isApiRequest('/poweradmin/api/internal/user'));
    }

    public function testRegularWebPageIsNotApi(): void
    {
        $this->assertFalse($this->isApiRequest('/index.php?page=list_forward_zones'));
    }

    public function testLoginPageIsNotApi(): void
    {
        $this->assertFalse($this->isApiRequest('/login'));
    }

    public function testHtmlPageContainingApiSegmentIsNotApi(): void
    {
        // A web page whose path merely contains "api" must still redirect to login.
        $this->assertFalse($this->isApiRequest('/settings/api/logs'));
        $this->assertFalse($this->isApiRequest('/api-keys'));
    }

    public function testApiPathInQueryStringIsNotApi(): void
    {
        // An API-looking return URL in the query string must not flip detection.
        $this->assertFalse($this->isApiRequest('/zones/forward?return=/api/v1/zones/1'));
    }

    public function testUnauthorizedResponseCarriesTheContractBodyAndStatus(): void
    {
        $service = new AuthenticationService(
            $this->createMock(SessionService::class),
            $this->createMock(RedirectService::class),
            $this->createMock(ConfigurationInterface::class)
        );

        $response = $service->apiUnauthorizedResponse();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('{"error":true,"message":"Unauthorized"}', $response->getContent());
    }

    public function testApiRequestIsAnsweredWithTheUnauthorizedResponseInsteadOfALoginRedirect(): void
    {
        $redirects = $this->createMock(RedirectService::class);
        $redirects->expects($this->once())->method('send')->with($this->callback(
            fn(Response $response) => $response->getStatusCode() === 401
        ));
        $redirects->expects($this->never())->method('redirectTo');
        $service = new AuthenticationService(
            $this->createMock(SessionService::class),
            $redirects,
            $this->createMock(ConfigurationInterface::class)
        );
        $_SERVER['REQUEST_URI'] = '/api/internal/zone';

        $service->auth(new FlashMessage('', 'danger'));
    }
}
