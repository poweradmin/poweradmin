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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\AbstractApiController;
use Poweradmin\Application\Controller\RequestHalted;
use ReflectionClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The real body-decoding and 405 helpers on AbstractApiController: a scalar
 * JSON body must be rejected the same as a malformed one (it used to slip
 * through a truthiness check and explode later as a 500), and a 405 must carry
 * the RFC 9110 Allow header.
 */
class AbstractApiControllerHelpersTest extends TestCase
{
    private function makeController(Request $request): TestableAbstractApiHelpersController
    {
        $controller = (new ReflectionClass(TestableAbstractApiHelpersController::class))
            ->newInstanceWithoutConstructor();

        $property = new ReflectionClass(AbstractApiController::class);
        $requestProperty = $property->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        return $controller;
    }

    private function jsonRequest(string $content): Request
    {
        return new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], $content);
    }

    public function testObjectBodyIsReturnedAsArray(): void
    {
        $controller = $this->makeController($this->jsonRequest('{"name":"www","type":"A"}'));

        $this->assertSame(
            ['name' => 'www', 'type' => 'A'],
            $controller->callGetValidatedJsonBody()
        );
    }

    public function testScalarStringBodyIsRejected(): void
    {
        $controller = $this->makeController($this->jsonRequest('"abc"'));

        $this->assertNull($controller->callGetValidatedJsonBody());
    }

    public function testScalarNumberBodyIsRejected(): void
    {
        $controller = $this->makeController($this->jsonRequest('42'));

        $this->assertNull($controller->callGetValidatedJsonBody());
    }

    public function testEmptyArrayBodyIsRejected(): void
    {
        $controller = $this->makeController($this->jsonRequest('[]'));

        $this->assertNull($controller->callGetValidatedJsonBody());
    }

    public function testMissingBodyWithoutFormDataIsRejected(): void
    {
        $controller = $this->makeController(new Request());

        $this->assertNull($controller->callGetValidatedJsonBody());
    }

    public function testFormEncodedFallbackIsAccepted(): void
    {
        $request = new Request([], ['name' => 'www'], [], [], [], [], '');
        $controller = $this->makeController($request);

        $this->assertSame(['name' => 'www'], $controller->callGetValidatedJsonBody());
    }

    public function testMethodNotAllowedCarriesTheAllowHeader(): void
    {
        $controller = $this->makeController(new Request());

        $response = $controller->callMethodNotAllowed(['GET', 'POST', 'DELETE']);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST, DELETE', $response->headers->get('Allow'));

        $body = json_decode((string)$response->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Method not allowed', $body['message']);
    }

    /**
     * The response goes out exactly once and the run ends through the same
     * halt the router catches for web controllers, not through exit.
     */
    public function testSendAndHaltWritesTheBodyOnceAndHaltsWithTheStatus(): void
    {
        $controller = $this->makeController(new Request());
        $response = new JsonResponse(['success' => false, 'data' => null, 'message' => 'Unauthorized'], 401);

        ob_start();
        try {
            $controller->callSendAndHalt($response);
        } catch (RequestHalted $halt) {
            $body = ob_get_clean();
        }

        $this->assertSame(RequestHalted::KIND_RESPONSE, $halt->kind);
        $this->assertSame('401', $halt->target);
        $this->assertSame('{"success":false,"data":null,"message":"Unauthorized"}', $body);
    }
}
