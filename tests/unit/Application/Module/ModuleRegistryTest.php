<?php

namespace Poweradmin\Tests\Unit\Application\Module;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Module\ModuleManifest;
use Poweradmin\Application\Module\ModuleRegistry;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Module\SecondaryZoneImport\SecondaryZoneImportModule;
use Poweradmin\Tests\Unit\Module\StubModule;

class ModuleRegistryTest extends TestCase
{
    private function createConfigMock(array $configMap): ConfigurationManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            function (string $group, string $key, mixed $default = null) use ($configMap) {
                return $configMap[$group][$key] ?? $default;
            }
        );
        return $config;
    }

    public function testModuleEnabledViaNewConfigKey(): void
    {
        $config = $this->createConfigMock([
            'modules' => [
                'dns_wizards.enabled' => true,
                'email_previews.enabled' => true,
            ],
        ]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $enabled = $registry->getEnabledModules();
        $this->assertArrayHasKey('dns_wizards', $enabled);
        $this->assertArrayHasKey('email_previews', $enabled);
    }

    public function testModuleDisabledViaNewConfigKey(): void
    {
        $config = $this->createConfigMock([
            'modules' => [
                'dns_wizards.enabled' => false,
                'email_previews.enabled' => false,
            ],
        ]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $enabled = $registry->getEnabledModules();
        $this->assertArrayNotHasKey('dns_wizards', $enabled);
        $this->assertArrayNotHasKey('email_previews', $enabled);
    }

    public function testDnsWizardsEnabledViaLegacyStandaloneConfig(): void
    {
        $config = $this->createConfigMock([
            'dns_wizards' => [
                'enabled' => true,
            ],
        ]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $enabled = $registry->getEnabledModules();
        $this->assertArrayHasKey('dns_wizards', $enabled);
    }

    public function testEmailPreviewsEnabledViaLegacyMiscConfig(): void
    {
        $config = $this->createConfigMock([
            'misc' => [
                'email_previews_enabled' => true,
            ],
        ]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $enabled = $registry->getEnabledModules();
        $this->assertArrayHasKey('email_previews', $enabled);
    }

    public function testNewConfigKeyTakesPriorityOverLegacy(): void
    {
        $config = $this->createConfigMock([
            'modules' => [
                'dns_wizards.enabled' => false,
                'email_previews.enabled' => false,
            ],
            'dns_wizards' => [
                'enabled' => true,
            ],
            'misc' => [
                'email_previews_enabled' => true,
            ],
        ]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $enabled = $registry->getEnabledModules();
        $this->assertArrayNotHasKey('dns_wizards', $enabled, 'New config false should override legacy true');
        $this->assertArrayNotHasKey('email_previews', $enabled, 'New config false should override legacy true');
    }

    public function testEmailPreviewsLegacyMiscFallbackOnlyWhenNoModuleKey(): void
    {
        // Simulate: modules.email_previews.enabled is not set, email_previews.enabled is not set,
        // but misc.email_previews_enabled is true
        $config = $this->createConfigMock([
            'misc' => [
                'email_previews_enabled' => true,
            ],
        ]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $enabled = $registry->getEnabledModules();
        $this->assertArrayHasKey('email_previews', $enabled);
    }

    public function testModulesDisabledByDefaultWhenNoConfig(): void
    {
        $config = $this->createConfigMock([]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $enabled = $registry->getEnabledModules();
        $this->assertArrayNotHasKey('dns_wizards', $enabled);
        $this->assertArrayNotHasKey('email_previews', $enabled);
    }

    public function testLoadModulesOnlyRunsOnce(): void
    {
        $config = $this->createConfigMock([
            'modules' => [
                'csv_export.enabled' => true,
            ],
        ]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();
        $firstCount = count($registry->getEnabledModules());

        $registry->loadModules();
        $secondCount = count($registry->getEnabledModules());

        $this->assertSame($firstCount, $secondCount);
    }

    public function testDnsWizardsStandaloneKeyFallbackWhenModuleKeyAbsent(): void
    {
        // Legacy config: dns_wizards.enabled = true, no modules section
        $config = $this->createConfigMock([
            'dns_wizards' => [
                'enabled' => true,
            ],
        ]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $enabled = $registry->getEnabledModules();
        $this->assertArrayHasKey('dns_wizards', $enabled);
    }

    public function testEmailPreviewsStandaloneKeyFallbackWhenModuleKeyAbsent(): void
    {
        // Legacy config: email_previews.enabled = true, no modules section
        $config = $this->createConfigMock([
            'email_previews' => [
                'enabled' => true,
            ],
        ]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $enabled = $registry->getEnabledModules();
        $this->assertArrayHasKey('email_previews', $enabled);
    }

    public function testLoadsEveryManifestModuleWhenAllAreEnabled(): void
    {
        $modules = [];
        foreach (array_keys(ModuleManifest::MODULES) as $name) {
            $modules["$name.enabled"] = true;
        }
        $config = $this->createConfigMock(['modules' => $modules]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $this->assertSame(array_keys(ModuleManifest::MODULES), array_keys($registry->getEnabledModules()));
        foreach ($registry->getEnabledModules() as $name => $module) {
            $this->assertInstanceOf(ModuleManifest::MODULES[$name], $module);
        }
    }

    public function testLoadsFromTheGivenModuleListInsteadOfTheManifest(): void
    {
        $config = $this->createConfigMock([
            'modules' => [
                'csv_export.enabled' => true,
                'stub.enabled' => true,
            ],
        ]);

        $registry = new ModuleRegistry($config, ['stub' => StubModule::class]);
        $registry->loadModules();

        $this->assertSame(['stub'], array_keys($registry->getEnabledModules()));
        $this->assertSame([['label' => 'Stub', 'url' => '/stub']], $registry->getNavItems());
    }

    public function testOneInstanceAnswersLikeThreeSeparateOnes(): void
    {
        $configMap = [
            'modules' => [
                'csv_export.enabled' => true,
                'zone_import_export.enabled' => true,
                'whois.enabled' => true,
                'rdap.enabled' => true,
                'dns_wizards.enabled' => true,
                'secondary_zone_import.enabled' => true,
                'whois.restrict_to_admin' => true,
            ],
            'dns' => ['backend' => 'api'],
        ];
        $build = function () use ($configMap): ModuleRegistry {
            $registry = new ModuleRegistry($this->createConfigMock($configMap));
            $registry->loadModules();
            return $registry;
        };
        $snapshot = static function (ModuleRegistry $registry): array {
            $capabilities = [];
            foreach (['zone_export', 'zone_import', 'whois_lookup', 'rdap_lookup', 'dns_wizard'] as $capability) {
                $capabilities[$capability] = [
                    $registry->getCapabilityData($capability, ['zone_id' => 7], false),
                    $registry->getCapabilityData($capability, ['zone_id' => 7], true),
                ];
            }
            return [
                array_keys($registry->getEnabledModules()),
                $registry->getRoutes(),
                $registry->getNavItems(false),
                $registry->getNavItems(true),
                $capabilities,
            ];
        };

        $once = $build();
        $expected = $snapshot($once);
        $this->assertNotSame([], $expected[1]);
        $this->assertNotSame([], $expected[4]['zone_export'][0]);

        // A second loadModules() on the same instance is a no-op, so the one
        // instance the router keeps answers the same after any number of readers.
        $once->loadModules();
        $this->assertSame($expected, $snapshot($once));
        $this->assertSame($expected, $snapshot($build()));
        $this->assertSame($expected, $snapshot($build()));
    }

    public function testModulesReadTheRegistryConfiguration(): void
    {
        $modules = ['secondary_zone_import' => SecondaryZoneImportModule::class];
        $sql = new ModuleRegistry($this->createConfigMock([
            'modules' => ['secondary_zone_import.enabled' => true],
            'dns' => ['backend' => 'sql'],
        ]), $modules);
        $api = new ModuleRegistry($this->createConfigMock([
            'modules' => ['secondary_zone_import.enabled' => true],
            'dns' => ['backend' => 'api'],
        ]), $modules);
        $sql->loadModules();
        $api->loadModules();

        $this->assertSame([], $sql->getRoutes());
        $this->assertSame(['module_secondary_zone_import', 'module_secondary_zone_import_status'], array_column($api->getRoutes(), 'name'));
    }
}
