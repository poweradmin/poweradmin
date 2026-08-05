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
use Poweradmin\Application\Controller\Api\V2\ZoneMetadataController;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use ReflectionClass;
use Symfony\Component\HttpFoundation\JsonResponse;

class ZoneMetadataControllerTest extends TestCase
{
    private ReflectionClass $controllerReflection;
    private array $configBackup = [];
    private bool $configInitializedBackup = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controllerReflection = new ReflectionClass(ZoneMetadataController::class);

        $config = ConfigurationManager::getInstance();
        $this->configBackup = $this->configProperty('settings')->getValue($config);
        $this->configInitializedBackup = $this->configProperty('initialized')->getValue($config);
    }

    protected function tearDown(): void
    {
        $config = ConfigurationManager::getInstance();
        $this->configProperty('settings')->setValue($config, $this->configBackup);
        $this->configProperty('initialized')->setValue($config, $this->configInitializedBackup);

        parent::tearDown();
    }

    public function testServerManagedKindsAreRejectedOnEveryBackend(): void
    {
        $sqlController = $this->createController();
        $this->assertStatus(403, $this->kindWriteRejection($sqlController, 'CATALOG-HASH'));

        $apiController = $this->createController($this->createMock(PowerdnsApiClient::class));
        $this->assertStatus(403, $this->kindWriteRejection($apiController, 'CATALOG-HASH'));
    }

    public function testSqlBackendAcceptsKindsThePowerDnsApiRefuses(): void
    {
        $controller = $this->createController();

        // The domainmetadata table takes any kind, including SOA-EDIT, which the
        // /metadata endpoint refuses.
        $this->assertNull($this->kindWriteRejection($controller, 'SOA-EDIT'));
        $this->assertNull($this->kindWriteRejection($controller, 'PRESIGNED'));
        $this->assertNull($this->kindWriteRejection($controller, 'BILLING-REF'));
    }

    public function testApiBackendRejectsKindsWithoutAnyWriteRoute(): void
    {
        $controller = $this->createController($this->createMock(PowerdnsApiClient::class));

        $this->assertNull($this->kindWriteRejection($controller, 'SOA-EDIT'));
        $this->assertNull($this->kindWriteRejection($controller, 'API-RECTIFY'));
        $this->assertStatus(403, $this->kindWriteRejection($controller, 'PRESIGNED'));
        $this->assertStatus(403, $this->kindWriteRejection($controller, 'AXFR-MASTER-TSIG'));
    }

    public function testApiBackendRequiresTheXPrefixOnCustomKinds(): void
    {
        $controller = $this->createController($this->createMock(PowerdnsApiClient::class));

        $this->assertStatus(422, $this->kindWriteRejection($controller, 'BILLING-REF'));
        $this->assertNull($this->kindWriteRejection($controller, 'X-BILLING-REF'));
    }

    public function testValuesOutsideAKindVocabularyAreRejected(): void
    {
        $controller = $this->createController();

        $this->assertStatus(422, $this->invalidValueError($controller, 'SOA-EDIT-API', ['BOGUS']));
        // DEFAULT belongs to SOA-EDIT-API, not to SOA-EDIT.
        $this->assertStatus(422, $this->invalidValueError($controller, 'SOA-EDIT', ['DEFAULT']));
        $this->assertNull($this->invalidValueError($controller, 'SOA-EDIT', ['INCEPTION-INCREMENT']));
        $this->assertNull($this->invalidValueError($controller, 'ALLOW-AXFR-FROM', ['192.0.2.10']));
    }

    public function testConfiguredVocabularyNarrowsAcceptedValues(): void
    {
        $controller = $this->createController(null, ['dns' => ['soa_edit_api_options' => ['EPOCH']]]);

        $this->assertNull($this->invalidValueError($controller, 'SOA-EDIT-API', ['EPOCH']));
        $this->assertStatus(422, $this->invalidValueError($controller, 'SOA-EDIT-API', ['INCREASE']));
    }

    public function testLoadMetadataListsZoneObjectKindsOnceAndIncludesSoaEdit(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([
            ['kind' => 'SOA-EDIT-API', 'metadata' => ['EPOCH']],
            ['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['192.0.2.10']],
        ]);
        $apiClient->method('getZone')->willReturn([
            'soa_edit_api' => 'EPOCH',
            'soa_edit' => 'INCEPTION-INCREMENT',
        ]);

        $zoneRepository = $this->createMock(DbZoneRepository::class);
        $zoneRepository->method('getZone')->willReturn(['id' => 1, 'name' => 'example.com']);

        $controller = $this->createController($apiClient);
        $this->setProperty($controller, 'zoneRepository', $zoneRepository);

        $byKind = [];
        foreach ($this->invokePrivateMethod($controller, 'loadMetadata', [1]) as $row) {
            $byKind[$row['kind']][] = $row['content'];
        }

        $this->assertSame(['EPOCH'], $byKind['SOA-EDIT-API']);
        $this->assertSame(['INCEPTION-INCREMENT'], $byKind['SOA-EDIT']);
        $this->assertSame(['192.0.2.10'], $byKind['ALLOW-AXFR-FROM']);
    }

    public function testSaveRoutesZoneObjectKindsThroughTheZoneEndpoint(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->expects($this->once())
            ->method('updateZoneProperties')
            ->with('example.com', ['soa_edit' => 'EPOCH'])
            ->willReturn(true);
        $apiClient->expects($this->never())->method('updateZoneMetadata');

        $zoneRepository = $this->createMock(DbZoneRepository::class);
        $zoneRepository->method('getZone')->willReturn(['id' => 1, 'name' => 'example.com']);

        $controller = $this->createController($apiClient);
        $this->setProperty($controller, 'zoneRepository', $zoneRepository);

        $this->assertTrue(
            $this->invokePrivateMethod($controller, 'saveMetadataKind', [1, 'SOA-EDIT', ['EPOCH']])
        );
    }

    public function testDeleteClearsBooleanZoneObjectKindsWithFalse(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->expects($this->once())
            ->method('updateZoneProperties')
            ->with('example.com', ['api_rectify' => false])
            ->willReturn(true);

        $zoneRepository = $this->createMock(DbZoneRepository::class);
        $zoneRepository->method('getZone')->willReturn(['id' => 1, 'name' => 'example.com']);

        $controller = $this->createController($apiClient);
        $this->setProperty($controller, 'zoneRepository', $zoneRepository);

        $this->assertTrue(
            $this->invokePrivateMethod($controller, 'deleteMetadataKindStorage', [1, 'API-RECTIFY'])
        );
    }

    private function kindWriteRejection(ZoneMetadataController $controller, string $kind): ?JsonResponse
    {
        return $this->invokePrivateMethod($controller, 'kindWriteRejection', [$kind]);
    }

    private function invalidValueError(ZoneMetadataController $controller, string $kind, array $values): ?JsonResponse
    {
        return $this->invokePrivateMethod($controller, 'invalidValueError', [$kind, $values]);
    }

    private function assertStatus(int $expected, ?JsonResponse $response): void
    {
        $this->assertNotNull($response);
        $this->assertSame($expected, $response->getStatusCode());
    }

    private function createController(?PowerdnsApiClient $apiClient = null, array $overrides = []): ZoneMetadataController
    {
        $controller = $this->controllerReflection->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'zoneRepository', $this->createMock(DbZoneRepository::class));
        $this->setProperty($controller, 'apiClient', $apiClient);
        $this->setBaseControllerProperty($controller, 'config', $this->createRuntimeConfig($overrides));

        return $controller;
    }

    private function createRuntimeConfig(array $overrides = []): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();

        $settings = [
            'database' => ['type' => 'mysql'],
            'pdns_api' => ['url' => '', 'key' => '', 'server_name' => 'localhost'],
        ];
        foreach ($overrides as $group => $values) {
            $settings[$group] = array_merge($settings[$group] ?? [], $values);
        }

        $this->configProperty('settings')->setValue($config, $settings);
        $this->configProperty('initialized')->setValue($config, true);

        return $config;
    }

    private function configProperty(string $name): \ReflectionProperty
    {
        $property = (new ReflectionClass(ConfigurationManager::class))->getProperty($name);
        $property->setAccessible(true);

        return $property;
    }

    private function invokePrivateMethod(object $object, string $methodName, array $arguments = []): mixed
    {
        $method = $this->controllerReflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }

    private function setProperty(object $object, string $propertyName, mixed $value): void
    {
        $property = $this->controllerReflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function setBaseControllerProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new ReflectionClass(ZoneMetadataController::class);
        while ($reflection !== false && !$reflection->hasProperty($propertyName)) {
            $reflection = $reflection->getParentClass();
        }

        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
