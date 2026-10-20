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

use Poweradmin\Application\Controller\Api\Internal\UserPreferencesController;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsController;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Tests\Unit\Application\Controller\Api\V2\V2ControllerTestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;

/**
 * A query parameter sent as an array (`?type[]=A`) makes InputBag::get() throw,
 * which the handlers' catch-all turned into a 500. Both API bases now refuse such
 * a request with a 400 before any handler reads the query (#1609).
 */
class ArrayQueryParameterGuardTest extends V2ControllerTestCase
{
    public function testV2RefusesAnArrayFilterWith400(): void
    {
        [$halt, $body] = $this->runGuard(ZonesRecordsController::class, ['type' => ['A']]);

        $this->assertSame(RequestHalted::KIND_RESPONSE, $halt->kind);
        $this->assertSame('400', $halt->target);
        $this->assertSame(
            ['success' => false, 'data' => null, 'message' => "Query parameter 'type' must be a single value"],
            $body
        );
    }

    public function testV2RefusesAKeyedArrayPage(): void
    {
        [$halt, $body] = $this->runGuard(ZonesRecordsController::class, ['sort' => 'name', 'page' => ['a' => 'x']]);

        $this->assertSame('400', $halt->target);
        $this->assertSame("Query parameter 'page' must be a single value", $body['message']);
    }

    public function testAnInvalidUtf8KeyStillAnswers400(): void
    {
        [$halt, $body] = $this->runGuard(ZonesRecordsController::class, ["\xFF" => ['x']]);

        $this->assertSame('400', $halt->target);
        $this->assertSame("Query parameter '?' must be a single value", $body['message']);
    }

    public function testInternalApiRefusesWithItsOwnErrorShape(): void
    {
        [$halt, $body] = $this->runGuard(UserPreferencesController::class, ['key' => ['theme']]);

        $this->assertSame('400', $halt->target);
        $this->assertSame(['error' => true, 'message' => "Query parameter 'key' must be a single value"], $body);
    }

    public function testScalarParametersPass(): void
    {
        $controller = $this->controllerWithQuery(ZonesRecordsController::class, ['type' => 'A', 'page' => '2', 'name' => '']);

        ob_start();
        try {
            $this->guard($controller)->invoke($controller);
        } finally {
            $output = (string)ob_get_clean();
        }

        $this->assertSame('', $output);
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $query
     * @return array{0: RequestHalted, 1: array<string, mixed>}
     */
    private function runGuard(string $class, array $query): array
    {
        $controller = $this->controllerWithQuery($class, $query);

        ob_start();
        try {
            $this->guard($controller)->invoke($controller);
        } catch (RequestHalted $halt) {
            $body = json_decode((string)ob_get_clean(), true);
            $this->assertIsArray($body);

            return [$halt, $body];
        }
        ob_end_clean();
        $this->fail('Expected the guard to halt');
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $query
     */
    private function controllerWithQuery(string $class, array $query): object
    {
        $controller = $this->bareController($class);
        $this->injectBaseCollaborators($controller);
        $this->inject($controller, 'request', Request::create('/api', 'GET', $query));

        return $controller;
    }

    private function guard(object $controller): ReflectionMethod
    {
        return new ReflectionMethod($controller, 'refuseArrayQueryParameters');
    }
}
