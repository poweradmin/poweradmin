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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\Internal;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\Internal\ZoneController;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The internal /api/internal/zone handlers previously built responses but never
 * returned or sent them, so every call produced an empty 200. They must now
 * return a JsonResponse that run() sends (audit H8).
 */
class ZoneControllerResponseTest extends TestCase
{
    public function testGetZoneReturnsJsonResponseForInvalidId(): void
    {
        $controller = (new ReflectionClass(ZoneController::class))->newInstanceWithoutConstructor();

        // id=0 is rejected before any permission/repository access, so no other
        // collaborators need to be wired up.
        $request = Request::create('/api/internal/zone?action=get&id=0');
        $property = new ReflectionProperty($controller, 'request');
        $property->setAccessible(true);
        $property->setValue($controller, $request);

        $method = new ReflectionMethod($controller, 'getZone');
        $method->setAccessible(true);
        $response = $method->invoke($controller);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('invalid zone ID', (string)$response->getContent());
    }
}
