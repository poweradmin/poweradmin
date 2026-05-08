<?php

namespace Poweradmin\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Module\ModuleRegistry;

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

    public function testGetAllModulesReturnsAllRegisteredModules(): void
    {
        $config = $this->createConfigMock([
            'modules' => [
                'csv_export.enabled' => true,
            ],
        ]);

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $all = $registry->getAllModules();
        $this->assertGreaterThan(count($registry->getEnabledModules()), count($all));
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
}
