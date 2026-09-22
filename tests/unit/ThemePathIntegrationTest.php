<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Service\StyleManager;
use TestHelpers\FakeConfiguration;

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
    }

    protected function tearDown(): void
    {
        // Clean up the temporary directory structure
        $this->removeDirectory($this->tempDir);
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
     * Test the issue reported in the bug: template path not being correctly constructed
     * This test specifically verifies if the templates/theme_name path is constructed correctly
     */
    public function testThemePathConstruction(): void
    {
        // Test case 1: Default configuration (will cause the issue)
        $config = new FakeConfiguration([
            'interface' => [
                'theme' => 'default',
                'style' => 'light',
                'theme_base_path' => 'templates',
            ]
        ]);

        // Create StyleManager with the problematic configuration
        $styleManager = $this->styleManagerFor($config);

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
        $config = new FakeConfiguration([
            'interface' => [
                'theme' => 'custom',
                'style' => 'dark',
                'theme_base_path' => 'templates',
            ]
        ]);

        // Create StyleManager with the custom theme
        $styleManager = $this->styleManagerFor($config);

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

    private function styleManagerFor(FakeConfiguration $config): StyleManager
    {
        return new StyleManager(
            $config->get('interface', 'style'),
            $this->tempDir . '/' . $config->get('interface', 'theme_base_path'),
            $config->get('interface', 'theme')
        );
    }
}
