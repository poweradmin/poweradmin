<?php

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditZoneMetadataController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use ReflectionClass;

class EditZoneMetadataControllerTest extends TestCase
{
    private ReflectionClass $controllerReflection;
    private array $configBackup = [];
    private bool $configInitializedBackup = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controllerReflection = new ReflectionClass(EditZoneMetadataController::class);

        $configReflection = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $configReflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $initializedProperty = $configReflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $config = ConfigurationManager::getInstance();
        $this->configBackup = $settingsProperty->getValue($config);
        $this->configInitializedBackup = $initializedProperty->getValue($config);
    }

    protected function tearDown(): void
    {
        $configReflection = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $configReflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $initializedProperty = $configReflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $config = ConfigurationManager::getInstance();
        $settingsProperty->setValue($config, $this->configBackup);
        $initializedProperty->setValue($config, $this->configInitializedBackup);

        parent::tearDown();
    }

    public function testMetadataDefinitionsIncludeAllKindsWhenApiIsNotConfigured(): void
    {
        $controller = $this->createControllerWithConfig([]);

        $definitions = $this->invokePrivateMethod($controller, 'getMetadataDefinitionsForTemplate');
        $kinds = array_column($definitions, 'kind');

        $this->assertContains('NOTIFY-DNSUPDATE', $kinds);
        $this->assertContains('AXFR-MASTER-TSIG', $kinds);
        $this->assertContains('API-RECTIFY', $kinds);
        $this->assertContains('SOA-EDIT-API', $kinds);
        $this->assertContains('SIGNALING-ZONE', $kinds);
        $this->assertContains('RFC1123-CONFORMANCE', $kinds);
    }

    public function testMetadataDefinitionsIncludeAllKindsWhenApiVersionIsUnknown(): void
    {
        $controller = $this->createControllerWithConfig([
            'pdns_api' => [
                'url' => 'http://127.0.0.1:8081/',
                'key' => 'test-key',
                'server_name' => 'localhost',
            ],
        ]);
        $this->setProperty($controller, 'powerDnsVersion', '');

        $definitions = $this->invokePrivateMethod($controller, 'getMetadataDefinitionsForTemplate');
        $kinds = array_column($definitions, 'kind');

        $this->assertContains('NOTIFY-DNSUPDATE', $kinds);
        $this->assertContains('AXFR-MASTER-TSIG', $kinds);
        $this->assertContains('API-RECTIFY', $kinds);
        $this->assertContains('SOA-EDIT-API', $kinds);
        $this->assertContains('SIGNALING-ZONE', $kinds);
        $this->assertContains('RFC1123-CONFORMANCE', $kinds);
    }

    public function testMetadataDefinitionsMarkKindsUnsupportedByDetectedApiVersionAsDisabled(): void
    {
        $controller = $this->createControllerWithConfig([
            'pdns_api' => [
                'url' => 'http://127.0.0.1:8081/',
                'key' => 'test-key',
                'server_name' => 'localhost',
            ],
        ]);
        $this->setProperty($controller, 'powerDnsVersion', '4.8.3');
        $this->setProperty($controller, 'apiClient', $this->createMock(\Poweradmin\Infrastructure\Api\PowerdnsApiClient::class));

        $definitions = $this->invokePrivateMethod($controller, 'getMetadataDefinitionsForTemplate');
        $byKind = [];
        foreach ($definitions as $definition) {
            $byKind[$definition['kind']] = $definition;
        }

        // Kinds whose min_version <= 4.8.3 are visible and enabled.
        $this->assertArrayHasKey('SLAVE-RENOTIFY', $byKind);
        $this->assertFalse($byKind['SLAVE-RENOTIFY']['disabled']);
        $this->assertArrayHasKey('GSS-ALLOW-AXFR-PRINCIPAL', $byKind);
        $this->assertFalse($byKind['GSS-ALLOW-AXFR-PRINCIPAL']['disabled']);

        // Kinds requiring a newer version are still listed but disabled, with
        // a min_version exposed for the "Requires X.Y+" hint in the template.
        $this->assertArrayHasKey('SIGNALING-ZONE', $byKind);
        $this->assertTrue($byKind['SIGNALING-ZONE']['disabled']);
        $this->assertSame('5.0.0', $byKind['SIGNALING-ZONE']['min_version']);
        $this->assertArrayHasKey('RFC1123-CONFORMANCE', $byKind);
        $this->assertTrue($byKind['RFC1123-CONFORMANCE']['disabled']);
    }

    public function testMetadataDefinitionsHideUnsupportedKindsWhenVersionIsUnknown(): void
    {
        $controller = $this->createControllerWithConfig([
            'pdns_api' => [
                'url' => 'http://127.0.0.1:8081/',
                'key' => 'test-key',
                'server_name' => 'localhost',
            ],
        ]);
        // Empty version simulates a failed detection - strict mode hides
        // version-gated kinds entirely so admins don't pick options the
        // server might reject.
        $this->setProperty($controller, 'powerDnsVersion', '');
        $this->setProperty($controller, 'apiClient', $this->createMock(\Poweradmin\Infrastructure\Api\PowerdnsApiClient::class));

        $definitions = $this->invokePrivateMethod($controller, 'getMetadataDefinitionsForTemplate');
        $kinds = array_column($definitions, 'kind');

        $this->assertNotContains('SIGNALING-ZONE', $kinds);
        $this->assertNotContains('RFC1123-CONFORMANCE', $kinds);
        // Kinds without any declared min_version stay visible even on
        // unknown server versions - they have always been supported.
        $this->assertContains('API-RECTIFY', $kinds);
        $this->assertContains('SOA-EDIT', $kinds);
    }

    public function testLoadMetadataReadsFromSqlEvenWhenApiConfigurationExists(): void
    {
        $expectedRows = [
            ['kind' => 'API-RECTIFY', 'content' => '1'],
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
        ];

        $zoneRepository = $this->createMock(DbZoneRepository::class);
        $zoneRepository->expects($this->once())
            ->method('getDomainMetadata')
            ->with(123)
            ->willReturn($expectedRows);

        $controller = $this->controllerReflection->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'zoneRepository', $zoneRepository);
        $this->setBaseControllerProperty($controller, 'config', $this->createRuntimeConfig([
            'pdns_api' => [
                'url' => 'http://127.0.0.1:8081/',
                'key' => 'test-key',
                'server_name' => 'localhost',
            ],
        ]));

        $rows = $this->invokePrivateMethod($controller, 'loadMetadata', [123, 'example.com']);

        $this->assertSame($expectedRows, $rows);
    }

    private function createControllerWithConfig(array $overrides): EditZoneMetadataController
    {
        $controller = $this->controllerReflection->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'zoneRepository', $this->createMock(DbZoneRepository::class));
        $this->setBaseControllerProperty($controller, 'config', $this->createRuntimeConfig($overrides));

        return $controller;
    }

    private function createRuntimeConfig(array $overrides = []): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $reflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $initializedProperty = $reflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $settings = [
            'database' => [
                'type' => 'mysql',
            ],
            'pdns_api' => [
                'url' => '',
                'key' => '',
                'server_name' => 'localhost',
            ],
        ];

        foreach ($overrides as $group => $values) {
            $settings[$group] = array_merge($settings[$group] ?? [], $values);
        }

        $settingsProperty->setValue($config, $settings);
        $initializedProperty->setValue($config, true);

        return $config;
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
        $baseReflection = new ReflectionClass($this->controllerReflection->getParentClass()->getName());
        $property = $baseReflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function getProjectRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
