<?php

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditZoneMetadataController;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
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
        $this->setProperty($controller, 'apiClient', $this->createMock(PowerdnsApiClient::class));

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
        $this->setProperty($controller, 'apiClient', $this->createMock(PowerdnsApiClient::class));

        $definitions = $this->invokePrivateMethod($controller, 'getMetadataDefinitionsForTemplate');
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

        $definitions = $this->invokePrivateMethod($controller, 'getMetadataDefinitionsForTemplate');
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

        $definitions = $this->invokePrivateMethod($controller, 'getMetadataDefinitionsForTemplate');
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

    public function testValidateMetadataRowsRejectsValueOutsideOptionsList(): void
    {
        $controller = $this->createControllerWithConfig([]);

        $errors = $this->invokePrivateMethod($controller, 'validateMetadataRows', [[
            ['kind' => 'SOA-EDIT-API', 'content' => 'BOGUS'],
        ]]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('SOA-EDIT-API', $errors[0]);
    }

    public function testValidateMetadataRowsAcceptsListedOptionAndFreeFormKinds(): void
    {
        $controller = $this->createControllerWithConfig([]);

        $errors = $this->invokePrivateMethod($controller, 'validateMetadataRows', [[
            ['kind' => 'SOA-EDIT-API', 'content' => 'EPOCH'],
            ['kind' => 'SOA-EDIT', 'content' => 'INCEPTION-INCREMENT'],
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
        ]]);

        $this->assertSame([], $errors);
    }

    public function testSaveMetadataViaApiRoutesSerialPoliciesThroughZoneProperties(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([]);
        // One lookup covers every zone-object kind absent from the form.
        $apiClient->expects($this->once())->method('getZone')->willReturn([]);
        $apiClient->expects($this->once())
            ->method('updateZoneProperties')
            ->with('example.com', ['soa_edit_api' => 'EPOCH', 'soa_edit' => 'INCEPTION-INCREMENT'])
            ->willReturn(true);
        $apiClient->expects($this->once())
            ->method('updateZoneMetadata')
            ->willReturn(true);

        $controller = $this->createControllerWithConfig([]);
        $this->setProperty($controller, 'apiClient', $apiClient);

        $result = $this->invokePrivateMethod($controller, 'saveMetadataViaApi', ['example.com', [
            ['kind' => 'SOA-EDIT-API', 'content' => 'EPOCH'],
            ['kind' => 'SOA-EDIT', 'content' => 'INCEPTION-INCREMENT'],
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
        ]]);

        $this->assertTrue($result['success']);
    }

    public function testSaveMetadataViaApiClearsRemovedSerialPolicies(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([]);
        $apiClient->expects($this->once())
            ->method('getZone')
            ->with('example.com')
            ->willReturn(['soa_edit_api' => 'DEFAULT', 'soa_edit' => 'EPOCH']);
        $apiClient->expects($this->once())
            ->method('updateZoneProperties')
            ->with('example.com', ['soa_edit_api' => '', 'soa_edit' => ''])
            ->willReturn(true);

        $controller = $this->createControllerWithConfig([]);
        $this->setProperty($controller, 'apiClient', $apiClient);

        $result = $this->invokePrivateMethod($controller, 'saveMetadataViaApi', ['example.com', []]);

        $this->assertTrue($result['success']);
    }

    public function testLoadMetadataViaApiDoesNotDuplicateZonePropertyKinds(): void
    {
        // PowerDNS lists SOA-EDIT-API/SOA-EDIT in /metadata AND on the zone
        // object; the editor must show each policy only once.
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([
            ['kind' => 'SOA-EDIT-API', 'metadata' => ['EPOCH']],
            ['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['192.0.2.10']],
        ]);
        $apiClient->method('getZone')->willReturn(['soa_edit_api' => 'EPOCH']);

        $controller = $this->createControllerWithConfig([]);
        $this->setProperty($controller, 'apiClient', $apiClient);

        $rows = $this->invokePrivateMethod($controller, 'loadMetadataViaApi', ['example.com']);
        $kinds = array_count_values(array_column($rows, 'kind'));

        $this->assertSame(1, $kinds['SOA-EDIT-API']);
        $this->assertSame(1, $kinds['ALLOW-AXFR-FROM']);
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

    public function testSaveMetadataViaApiRoutesZoneObjectKindsWithTheirJsonType(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([]);
        $apiClient->method('getZone')->willReturn([]);
        $apiClient->expects($this->once())
            ->method('updateZoneProperties')
            ->with('example.com', [
                'api_rectify' => true,
                'nsec3param' => '1 0 0 -',
                'nsec3narrow' => true,
            ])
            ->willReturn(true);

        $controller = $this->createControllerWithConfig([]);
        $this->setProperty($controller, 'apiClient', $apiClient);

        $result = $this->invokePrivateMethod($controller, 'saveMetadataViaApi', ['example.com', [
            ['kind' => 'API-RECTIFY', 'content' => '1'],
            ['kind' => 'NSEC3PARAM', 'content' => '1 0 0 -'],
            ['kind' => 'NSEC3NARROW', 'content' => '1'],
        ]]);

        $this->assertTrue($result['success']);
    }

    public function testValidateMetadataRowsRejectsNsec3NarrowWithoutNsec3Param(): void
    {
        $controller = $this->createControllerWithConfig([]);

        $errors = $this->invokePrivateMethod($controller, 'validateMetadataRows', [[
            ['kind' => 'NSEC3NARROW', 'content' => '1'],
        ]]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('NSEC3PARAM', $errors[0]);

        $this->assertSame([], $this->invokePrivateMethod($controller, 'validateMetadataRows', [[
            ['kind' => 'NSEC3NARROW', 'content' => '1'],
            ['kind' => 'NSEC3PARAM', 'content' => '1 0 0 -'],
        ]]));

        // Turning narrow mode off needs no companion row.
        $this->assertSame([], $this->invokePrivateMethod($controller, 'validateMetadataRows', [[
            ['kind' => 'NSEC3NARROW', 'content' => '0'],
        ]]));
    }

    public function testLoadMetadataViaApiSourcesZoneObjectKindsOnlyFromTheZone(): void
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn([
            ['kind' => 'NSEC3PARAM', 'metadata' => ['1 0 0 -']],
            ['kind' => 'API-RECTIFY', 'metadata' => ['1']],
        ]);
        $apiClient->method('getZone')->willReturn([
            'nsec3param' => '1 0 5 ab',
            'nsec3narrow' => true,
            'api_rectify' => false,
        ]);

        $controller = $this->createControllerWithConfig([]);
        $this->setProperty($controller, 'apiClient', $apiClient);

        $rows = $this->invokePrivateMethod($controller, 'loadMetadataViaApi', ['example.com']);
        $byKind = [];
        foreach ($rows as $row) {
            $byKind[$row['kind']][] = $row['content'];
        }

        $this->assertSame(['1 0 5 ab'], $byKind['NSEC3PARAM']);
        $this->assertSame(['1'], $byKind['NSEC3NARROW']);
        // api_rectify is false on the zone object, so the stale /metadata row
        // must not resurface it.
        $this->assertArrayNotHasKey('API-RECTIFY', $byKind);
    }

    public function testRestrictedKindViolationsRejectChangesTheApiCannotStore(): void
    {
        [$controller] = $this->controllerWithApi();

        $errors = $this->invokePrivateMethod($controller, 'restrictedKindViolations', [
            ['CATALOG-HASH', 'PRESIGNED', 'BILLING-REF'],
        ]);

        $joined = implode(' ', $errors);
        $this->assertCount(3, $errors);
        $this->assertStringContainsString('CATALOG-HASH', $joined);
        $this->assertStringContainsString('PRESIGNED', $joined);
        $this->assertStringContainsString('BILLING-REF', $joined);
    }

    public function testRestrictedKindViolationsAllowKindsTheApiCanStore(): void
    {
        [$controller] = $this->controllerWithApi();

        $this->assertSame([], $this->invokePrivateMethod($controller, 'restrictedKindViolations', [
            ['ALLOW-AXFR-FROM', 'SOA-EDIT', 'NSEC3PARAM', 'X-BILLING-REF'],
        ]));
    }

    public function testRestrictedKindViolationsOnSqlBackendOnlyBlockServerManagedKinds(): void
    {
        $controller = $this->createControllerWithConfig([]);

        $this->assertSame([], $this->invokePrivateMethod($controller, 'restrictedKindViolations', [
            ['PRESIGNED', 'BILLING-REF'],
        ]));
        $this->assertCount(1, $this->invokePrivateMethod($controller, 'restrictedKindViolations', [
            ['CATALOG-HASH'],
        ]));
    }

    public function testChangedKindsIgnoresReorderedValues(): void
    {
        $controller = $this->createControllerWithConfig([]);

        $this->assertSame([], $this->invokePrivateMethod($controller, 'changedKinds', [
            [
                ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.20'],
                ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
            ],
            [
                ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
                ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.20'],
            ],
        ]));
    }

    public function testSaveMetadataViaApiNeverWritesServerManagedKinds(): void
    {
        [$controller, $apiClient] = $this->controllerWithApi([
            ['kind' => 'CATALOG-HASH', 'metadata' => ['server-value']],
        ]);
        $apiClient->expects($this->never())->method('updateZoneMetadata');
        $apiClient->expects($this->never())->method('deleteZoneMetadata');

        $result = $this->invokePrivateMethod($controller, 'saveMetadataViaApi', ['example.com', [
            ['kind' => 'CATALOG-HASH', 'content' => 'server-value'],
        ]]);

        $this->assertTrue($result['success']);
    }

    public function testValidateMetadataRowsAcceptsRepeatedMultiValueKinds(): void
    {
        $controller = $this->createControllerWithConfig([]);

        $errors = $this->invokePrivateMethod($controller, 'validateMetadataRows', [[
            ['kind' => 'TSIG-ALLOW-DNSUPDATE', 'content' => 'key-one'],
            ['kind' => 'TSIG-ALLOW-DNSUPDATE', 'content' => 'key-two'],
            ['kind' => 'PUBLISH-CDS', 'content' => '2'],
            ['kind' => 'PUBLISH-CDS', 'content' => '4'],
        ]]);

        $this->assertSame([], $errors);
    }

    /**
     * @return array{0: EditZoneMetadataController, 1: PowerdnsApiClient}
     */
    private function controllerWithApi(array $metadata = [], array $zone = []): array
    {
        $apiClient = $this->createMock(PowerdnsApiClient::class);
        $apiClient->method('getZoneMetadata')->willReturn($metadata);
        $apiClient->method('getZone')->willReturn($zone);

        $controller = $this->createControllerWithConfig([]);
        $this->setProperty($controller, 'apiClient', $apiClient);

        return [$controller, $apiClient];
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
}
