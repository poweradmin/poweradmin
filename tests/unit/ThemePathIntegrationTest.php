<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\StyleManager;
use Poweradmin\BaseController;
use ReflectionClass;

/**
 * This test class verifies the integration between Configuration and StyleManager
 * to ensure the theme path issue is properly resolved.
 *
 * The bug reported was that Poweradmin didn't properly handle theme_base_path and theme
 * settings together, resulting in an error when trying to find template files.
 */
class ThemePathIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Set up the temporary directory structure for testing
        $this->tempDir = sys_get_temp_dir() . '/poweradmin_test_' . uniqid();

        // Create directories for templates
        mkdir($this->tempDir . '/templates', 0777, true);
        mkdir($this->tempDir . '/templates/default', 0777, true);
        mkdir($this->tempDir . '/templates/default/style', 0777, true);
        mkdir($this->tempDir . '/templates/custom', 0777, true);
        mkdir($this->tempDir . '/templates/custom/style', 0777, true);

        // Create style files
        file_put_contents($this->tempDir . '/templates/default/style/light.css', 'body { color: #000; }');
        file_put_contents($this->tempDir . '/templates/default/style/dark.css', 'body { color: #fff; background: #000; }');
        file_put_contents($this->tempDir . '/templates/custom/style/light.css', 'body { color: #333; }');
        file_put_contents($this->tempDir . '/templates/custom/style/dark.css', 'body { color: #eee; background: #222; }');

        // Reset the ConfigurationManager singleton
        $this->resetConfigurationManager();
    }

    protected function tearDown(): void
    {
        // Clean up the temporary directory structure
        $this->removeDirectory($this->tempDir);

        // Reset the ConfigurationManager singleton
        $this->resetConfigurationManager();
    }

    /**
     * Helper method to remove a directory and its contents recursively
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Reset the ConfigurationManager singleton between tests
     */
    private function resetConfigurationManager(): void
    {
        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $instanceProperty = $reflectionClass->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);

        $config = ConfigurationManager::getInstance();
        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $initializedProperty = $reflectionClass->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue($config, false);
    }

    /**
     * Mock the ConfigurationManager with specific settings
     */
    private function mockConfigurationManager(array $settings): void
    {
        $configManager = ConfigurationManager::getInstance();

        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $reflectionClass->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $settingsProperty->setValue($configManager, $settings);

        $initializedProperty = $reflectionClass->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue($configManager, true);
    }

    /**
     * Test the issue reported in the bug: template path not being correctly constructed
     * This test specifically verifies if the templates/theme_name path is constructed correctly
     */
    public function testThemePathConstruction(): void
    {
        // Test case 1: Default configuration (will cause the issue)
        $this->mockConfigurationManager([
            'interface' => [
                'theme' => 'default',
                'style' => 'light',
                'theme_base_path' => 'templates',
            ]
        ]);

        // Create StyleManager with the problematic configuration
        $styleManager = new StyleManager(
            'light',
            $this->tempDir . '/templates',
            'default'
        );

        // Get the styleDir property to verify the path
        $reflection = new \ReflectionObject($styleManager);
        $styleDir = $reflection->getProperty('styleDir');
        $styleDir->setAccessible(true);

        // This should be pointing to {tempDir}/templates/default/style
        $this->assertEquals(
            $this->tempDir . '/templates/default/style',
            $styleDir->getValue($styleManager),
            'The style directory path should be correctly constructed with theme_base_path and theme name'
        );

        // Verify that the style is still accessible
        $this->assertEquals('light', $styleManager->getSelectedStyle());
    }

    /**
     * Test using a custom theme path, which is more likely to encounter the issue
     */
    public function testCustomThemePath(): void
    {
        // Use a custom theme with the correct configuration
        $this->mockConfigurationManager([
            'interface' => [
                'theme' => 'custom',
                'style' => 'dark',
                'theme_base_path' => 'templates',
            ]
        ]);

        // Create StyleManager with the custom theme
        $styleManager = new StyleManager(
            'dark',
            $this->tempDir . '/templates',
            'custom'
        );

        // Get the styleDir property to verify the path
        $reflection = new \ReflectionObject($styleManager);
        $styleDir = $reflection->getProperty('styleDir');
        $styleDir->setAccessible(true);

        // This should be pointing to {tempDir}/templates/custom/style
        $this->assertEquals(
            $this->tempDir . '/templates/custom/style',
            $styleDir->getValue($styleManager),
            'The style directory path should be correctly constructed with custom theme'
        );

        // Verify that the style is still accessible
        $this->assertEquals('dark', $styleManager->getSelectedStyle());
    }
}
