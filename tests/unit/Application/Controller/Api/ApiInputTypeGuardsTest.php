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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\Internal\ValidationController;
use Poweradmin\Application\Controller\Api\V2\PermissionTemplatesController;
use Poweradmin\Application\Controller\Api\V2\ZoneMetadataController;
use Poweradmin\Application\Controller\Api\V2\ZoneTemplatesController;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsBulkController;
use Poweradmin\Application\Controller\Api\V2\ZonesRRSetsController;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A body field that is an array where a string or object is expected answers 400
 * instead of reaching trim(), strval() or a string function.
 */
class ApiInputTypeGuardsTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedValidationBodies(): array
    {
        return [
            'records as a string' => [['records' => 'abc']],
            'name as an array' => [['name' => ['x'], 'type' => 'A', 'content' => '192.0.2.1']],
            'content as an array' => [['name' => 'www', 'type' => 'A', 'content' => ['x']]],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('malformedValidationBodies')]
    public function testValidateRecordAnswers400ForAMalformedBody(array $body): void
    {
        $controller = $this->bare(ValidationController::class, 'POST', $body);

        $this->assertSame(400, $this->call($controller, 'validateRecord')->getStatusCode());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function nonStringTemplateFields(): array
    {
        return [
            'array name' => [['name' => ['x'], 'descr' => 'd', 'description' => 'd']],
            'array descr' => [['name' => 'n', 'descr' => ['x'], 'description' => ['x']]],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('nonStringTemplateFields')]
    public function testPermissionTemplateWritesRefuseNonStringFields(array $body): void
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);

        foreach ([['createPermissionTemplate', 'POST', []], ['updatePermissionTemplate', 'PUT', ['id' => '7']]] as [$handler, $method, $path]) {
            $controller = $this->bare(PermissionTemplatesController::class, $method, $body, $path);
            $this->set($controller, 'apiPermissionService', $permissions);

            $response = $this->call($controller, $handler);

            $this->assertSame(400, $response->getStatusCode(), $handler);
            $this->assertSame('Missing required fields: name, descr', $this->message($response), $handler);
        }
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('nonStringTemplateFields')]
    public function testZoneTemplateCreateRefusesNonStringFields(array $body): void
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canCreateZoneTemplate')->willReturn(true);
        $controller = $this->bare(ZoneTemplatesController::class, 'POST', $body);
        $this->set($controller, 'apiPermissionService', $permissions);

        $response = $this->call($controller, 'createZoneTemplate');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Missing required fields: name, description', $this->message($response));
    }

    public function testMetadataValuesHoldingArraysAreRefused(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('zoneExists')->willReturn(true);
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $controller = $this->bare(ZoneMetadataController::class, 'PUT', ['values' => [['192.0.2.10']]], ['id' => '3', 'kind' => 'ALLOW-AXFR-FROM']);
        $this->set($controller, 'zoneRepository', $zones);
        $this->set($controller, 'apiPermissionService', $permissions);

        $this->assertSame(400, $this->call($controller, 'updateMetadataKind')->getStatusCode());
    }

    /** @return array<string, array{mixed}> */
    public static function malformedRecordItems(): array
    {
        return [
            'string item' => ['192.0.2.1'],
            'array content' => [['content' => ['192.0.2.1']]],
        ];
    }

    #[DataProvider('malformedRecordItems')]
    public function testRrsetReplaceRefusesAMalformedRecordItem(mixed $item): void
    {
        $controller = $this->zoneWriteController(
            ZonesRRSetsController::class,
            ['name' => 'www', 'type' => 'A', 'ttl' => 60, 'records' => [$item]]
        );

        $response = $this->call($controller, 'replaceRRSet');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid field types in request body', $this->message($response));
    }

    /** @return array<string, array{mixed}> */
    public static function malformedOperations(): array
    {
        return [
            'string item' => ['create'],
            'number item' => [5],
            'array action' => [['action' => ['create']]],
        ];
    }

    #[DataProvider('malformedOperations')]
    public function testBulkOperationsRefuseAMalformedItem(mixed $operation): void
    {
        $controller = $this->zoneWriteController(ZonesRecordsBulkController::class, ['operations' => [$operation]]);

        $this->assertSame(400, $this->call($controller, 'bulkRecordOperations')->getStatusCode());
    }

    /** @param class-string $class @param array<string, mixed> $body */
    private function zoneWriteController(string $class, array $body): object
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZoneById')->willReturn(['id' => 3, 'type' => 'MASTER', 'name' => 'example.com']);
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneContent')->willReturn(true);
        $controller = $this->bare($class, 'POST', $body, ['id' => '3']);
        $this->set($controller, 'zoneRepository', $zones);
        $this->set($controller, 'permissionService', $permissions);

        return $controller;
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $body
     * @param array<string, string> $path
     */
    private function bare(string $class, string $method, array $body, array $path = []): object
    {
        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $request = Request::create('/api', $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], (string)json_encode($body));
        $this->set($controller, 'request', $request);
        foreach (['pathParameters' => $path, 'authenticatedUserId' => 5, 'apiKeyScope' => ApiKeyScope::unrestricted()] as $name => $value) {
            // The internal API base class carries none of these
            $this->set($controller, $name, $value, false);
        }

        return $controller;
    }

    private function set(object $controller, string $name, mixed $value, bool $required = true): void
    {
        for ($class = new ReflectionClass($controller); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                $class->getProperty($name)->setValue($controller, $value);
                return;
            }
        }

        if ($required) {
            $this->fail("No property $name on " . $controller::class);
        }
    }

    private function call(object $controller, string $handler): JsonResponse
    {
        return (new ReflectionMethod($controller, $handler))->invoke($controller);
    }

    private function message(JsonResponse $response): string
    {
        return (string)(json_decode((string)$response->getContent(), true)['message'] ?? '');
    }
}
