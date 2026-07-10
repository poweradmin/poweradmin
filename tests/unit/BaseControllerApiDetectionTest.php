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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\BaseController;
use ReflectionClass;
use ReflectionMethod;

/**
 * The API-route detectors must key off the real routed path, not requestData['page']
 * (which the router never sets for API routes and which ?page= could spoof) - audit M18.
 */
class BaseControllerApiDetectionTest extends TestCase
{
    private BaseController $controller;

    protected function setUp(): void
    {
        // Concrete BaseController built without its authenticating (DB-touching) constructor.
        $this->controller = (new ReflectionClass(ApiDetectionTestController::class))->newInstanceWithoutConstructor();
    }

    private function detect(string $method, string $requestUri): bool
    {
        $_SERVER['REQUEST_URI'] = $requestUri;
        $ref = new ReflectionMethod(BaseController::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->controller);
    }

    public function testInternalApiRouteDetected(): void
    {
        $this->assertTrue($this->detect('isInternalApiRoute', '/api/internal/zones'));
        $this->assertTrue($this->detect('isApiRequest', '/api/internal/zones'));
        $this->assertFalse($this->detect('isPublicApiRoute', '/api/internal/zones'));
    }

    public function testPublicApiRouteDetected(): void
    {
        $this->assertTrue($this->detect('isPublicApiRoute', '/api/v2/zones/1/records'));
        $this->assertTrue($this->detect('isApiRequest', '/api/v1/zones'));
        $this->assertFalse($this->detect('isInternalApiRoute', '/api/v2/zones'));
    }

    public function testSubfolderApiRouteDetected(): void
    {
        $this->assertTrue($this->detect('isInternalApiRoute', '/poweradmin/api/internal/user'));
        $this->assertTrue($this->detect('isApiRequest', '/poweradmin/api/v2/zones'));
    }

    public function testQueryStringDoesNotFlipDetection(): void
    {
        // The router never sets 'page' for API routes; a spoofed ?page= in the query
        // string must not make a web page look like an API route.
        $this->assertFalse($this->detect('isApiRequest', '/index.php?page=api/internal/x'));
        $this->assertFalse($this->detect('isInternalApiRoute', '/zones?return=/api/internal/x'));
    }

    public function testHtmlPageContainingApiSegmentIsNotApi(): void
    {
        $this->assertFalse($this->detect('isApiRequest', '/settings/api/logs'));
        $this->assertFalse($this->detect('isInternalApiRoute', '/settings/api/logs'));
    }
}
