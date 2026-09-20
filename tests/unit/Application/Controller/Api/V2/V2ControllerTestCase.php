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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds an /api/v2 controller without its constructor, so the api.enabled gate,
 * API key authentication and the database bootstrap are never involved. Private
 * collaborators are injected directly and private handlers invoked by reflection,
 * the same seam the Api/UsersController* tests use.
 */
abstract class V2ControllerTestCase extends TestCase
{
    /**
     * Instantiate a controller with no constructor run at all.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function bareController(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * Set a property declared anywhere in the controller's hierarchy.
     */
    protected function inject(object $controller, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($controller);
        while (!$reflection->hasProperty($property)) {
            $parent = $reflection->getParentClass();
            if ($parent === false) {
                self::fail(sprintf('No property "%s" on %s', $property, get_class($controller)));
            }
            $reflection = $parent;
        }
        $reflection->getProperty($property)->setValue($controller, $value);
    }

    /**
     * The collaborators every v2 controller touches on any code path: the request,
     * the logger, the config, a database handle whose prepare() always answers, and
     * a service factory so the create*() accessors never build real services.
     *
     * @param array<string, mixed>|null $body Decoded JSON body, or null for no body
     * @param array<string, mixed> $query Query string parameters
     */
    protected function injectBaseCollaborators(
        object $controller,
        string $method = 'GET',
        ?array $body = null,
        array $query = []
    ): void {
        $content = $body === null ? '' : (string)json_encode($body);
        $request = Request::create('/api/v2/zones/1', $method, $query, [], [], ['CONTENT_TYPE' => 'application/json'], $content);

        $this->inject($controller, 'request', $request);
        $this->inject($controller, 'logger', new NullLogger());
        $this->inject($controller, 'config', ConfigurationManager::getInstance());
        $this->inject($controller, 'db', $this->stubDb());
        $this->inject($controller, 'serviceFactory', $this->stubServiceFactory());
    }

    /**
     * A PDO whose prepare() returns a statement, so the request logging and the
     * username lookup on the response path never explode.
     */
    protected function stubDb(): PDO
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('apiuser');

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($statement);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);
        $db->method('rollBack')->willReturn(true);

        return $db;
    }

    /**
     * Routes every create*() accessor on BaseController through mocks. Only the
     * accessors a test actually reaches need a return value; the rest stay unused.
     */
    protected function stubServiceFactory(): ControllerServiceFactory
    {
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));

        return $factory;
    }

    /**
     * Invoke one of the controller's private request handlers.
     */
    protected function callHandler(object $controller, string $handler): JsonResponse
    {
        $method = new ReflectionMethod($controller, $handler);
        $method->setAccessible(true);

        $response = $method->invoke($controller);
        self::assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }

    /**
     * The decoded response envelope.
     *
     * @return array<string, mixed>
     */
    protected function decode(JsonResponse $response): array
    {
        $decoded = json_decode((string)$response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    protected function messageOf(JsonResponse $response): string
    {
        return (string)($this->decode($response)['message'] ?? '');
    }
}
