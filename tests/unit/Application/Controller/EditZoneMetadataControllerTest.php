<?php

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditZoneMetadataController;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Domain\Service\ZoneMetadataService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Application\Service\AuditService;
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

        $definitions = $this->definitions($controller, PdnsCapabilities::fromVersion(null));
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
        $this->useApiBackend($controller);

        $definitions = $this->definitions($controller, PdnsCapabilities::fromVersion('4.8.3'));
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
        $this->useApiBackend($controller);

        $definitions = $this->definitions($controller, PdnsCapabilities::fromVersion(null));
        $kinds = array_column($definitions, 'kind');

        $this->assertNotContains('SIGNALING-ZONE', $kinds);
        $this->assertNotContains('RFC1123-CONFORMANCE', $kinds);
        // Kinds without any declared min_version stay visible even on
        // unknown server versions - they have always been supported.
        $this->assertContains('API-RECTIFY', $kinds);
        $this->assertContains('SOA-EDIT', $kinds);
    }

    public function testMetadataDefinitionsHideKindWhoseOptionsAreDisabledByConfig(): void
    {
        $controller = $this->createControllerWithConfig([
            'dns' => ['soa_edit_api_options' => []],
        ]);

        $definitions = $this->definitions($controller, PdnsCapabilities::fromVersion(null));
        $byKind = array_column($definitions, null, 'kind');

        $this->assertArrayNotHasKey('SOA-EDIT-API', $byKind);
        $this->assertArrayNotHasKey('SOA-EDIT-DNSUPDATE', $byKind);
        // SOA-EDIT has its own config key and stays visible with full options
        $this->assertArrayHasKey('SOA-EDIT', $byKind);
        $this->assertNotEmpty($byKind['SOA-EDIT']['options']);
    }

    public function testMetadataDefinitionsNarrowOptionsByConfigList(): void
    {
        $controller = $this->createControllerWithConfig([
            'dns' => ['soa_edit_api_options' => ['EPOCH', 'DEFAULT']],
        ]);

        $definitions = $this->definitions($controller, PdnsCapabilities::fromVersion(null));
        $byKind = array_column($definitions, null, 'kind');

        $this->assertSame(['DEFAULT', 'EPOCH'], $byKind['SOA-EDIT-API']['options']);
    }

    public function testExistingRowOfHiddenKindRendersThroughCustomPathToSurviveSubmission(): void
    {
        // A row whose kind is not in the kind dropdown would otherwise submit
        // a different kind and silently rewrite the stored policy.
        $controller = $this->createControllerWithConfig([
            'dns' => ['soa_edit_api_options' => []],
        ]);

        $rows = $this->invokePrivateMethod($controller, 'prepareRowsForTemplate', [
            [['kind' => 'SOA-EDIT-API', 'content' => 'EPOCH']],
            ['SOA-EDIT', 'ALLOW-AXFR-FROM'],
        ]);

        $this->assertSame('__CUSTOM__', $rows[0]['kind_key']);
        $this->assertSame('SOA-EDIT-API', $rows[0]['custom_kind']);
    }

    private function createControllerWithConfig(array $overrides): EditZoneMetadataController
    {
        $controller = $this->controllerReflection->newInstanceWithoutConstructor();
        $config = $this->createRuntimeConfig($overrides);
        $this->setProperty($controller, 'zoneRepository', $this->createMock(DbZoneRepository::class));
        $this->setProperty($controller, 'metadataService', $this->metadataService($config, null));
        $this->setBaseControllerProperty($controller, 'config', $config);

        return $controller;
    }

    private function useApiBackend(EditZoneMetadataController $controller): void
    {
        $this->setProperty($controller, 'metadataService', $this->metadataService(ConfigurationManager::getInstance(), $this->createMock(PowerdnsApiClient::class)));
    }

    private function metadataService(ConfigurationManager $config, ?PowerdnsApiClient $apiClient): ZoneMetadataService
    {
        return new ZoneMetadataService(
            $this->createMock(DbZoneRepository::class),
            $config,
            $this->createMock(PermissionService::class),
            $this->createMock(AuditService::class),
            $this->createMock(RecordChangeLogger::class),
            $apiClient
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitions(EditZoneMetadataController $controller, PdnsCapabilities $caps): array
    {
        return $this->invokePrivateMethod($controller, 'getMetadataDefinitionsForTemplate', [true, $caps]);
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
}
