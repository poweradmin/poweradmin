<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Service\StyleManager;

class StyleManagerTest extends TestCase
{
    private string $testStyleDir;

    private function setCookieStyle(string $style): void
    {
        $_COOKIE['style'] = $style;
    }

    protected function setUp(): void
    {
        // Create test theme directory structure and style files for testing
        $this->testStyleDir = dirname(__DIR__, 2) . '/templates/default/style/';
    }

    protected function tearDown(): void
    {
        // Clean up cookies after each test
        if (isset($_COOKIE['style'])) {
            unset($_COOKIE['style']);
        }
    }

    public function testDefaultStyle()
    {
        $styleManager = new StyleManager();

        $this->assertEquals('light', $styleManager->getSelectedStyle());
    }

    public function testCustomStyle()
    {
        $styleManager = new StyleManager('dark', 'templates', 'default');

        $this->assertEquals('dark', $styleManager->getSelectedStyle());
    }

    public function testInvalidCustomStyle()
    {
        $styleManager = new StyleManager('nonexistent', 'templates', 'default');

        $this->assertEquals('light', $styleManager->getSelectedStyle());
    }

    public function testInvalidThemeDir()
    {
        $styleManager = new StyleManager('light', 'nonexistent_dir', 'default');

        $this->assertEquals('light', $styleManager->getSelectedStyle());
    }

    public function testStyleFromCookie()
    {
        $this->setCookieStyle('dark');
        $styleManager = new StyleManager('light', 'templates', 'default');

        $this->assertEquals('dark', $styleManager->getSelectedStyle());
    }

    public function testInvalidStyleFromCookie()
    {
        $this->setCookieStyle('nonexistent');
        $styleManager = new StyleManager('light', 'templates', 'default');

        $this->assertEquals('light', $styleManager->getSelectedStyle());
    }

    public function testValidStyleFromCookieWithCustomStyle()
    {
        $this->setCookieStyle('dark');
        $styleManager = new StyleManager('light', 'templates', 'default');

        $this->assertEquals('dark', $styleManager->getSelectedStyle());
    }

    public function testInvalidStyleFromCookieWithCustomStyle()
    {
        $this->setCookieStyle('nonexistent');
        $styleManager = new StyleManager('dark', 'templates', 'default');

        $this->assertEquals('dark', $styleManager->getSelectedStyle());
    }

    /**
     * This test validates if the theme path configuration issue is resolved
     * The issue was that Poweradmin wasn't taking the theme parameter into account
     * and was looking in the wrong location for templates
     */
    public function testThemeBasePath()
    {
        // Create a mock directory structure for testing
        $tempDir = sys_get_temp_dir() . '/poweradmin_test_' . uniqid();
        mkdir($tempDir . '/custom', 0777, true);
        mkdir($tempDir . '/custom/style', 0777, true);

        // Create a mock CSS file in the custom theme style directory
        file_put_contents($tempDir . '/custom/style/light.css', 'body { color: #000; }');

        try {
            // Test with default theme path but custom theme name
            $styleManager = new StyleManager('light', $tempDir, 'custom');
            $this->assertEquals('light', $styleManager->getSelectedStyle());

            // Test with full custom path and theme
            $styleManager = new StyleManager('nonexistent', $tempDir, 'custom');
            $this->assertEquals('light', $styleManager->getSelectedStyle());

            // Test with default theme_base_path (templates/default)
            // This should use the fallback default settings
            $styleManager = new StyleManager('nonexistent', 'templates', 'nonexistent_theme');
            $this->assertEquals('light', $styleManager->getSelectedStyle());

            // Test the exact scenario from the bug report
            // Using 'templates/default' as theme_base_path and expecting templates to work
            $styleManager = new StyleManager('light', $tempDir, 'custom');
            $reflection = new \ReflectionObject($styleManager);
            $styleDir = $reflection->getProperty('styleDir');
            $styleDir->setAccessible(true);

            // Verify that the styleDir property contains the correct path
            $this->assertEquals($tempDir . '/custom/style', $styleDir->getValue($styleManager));
        } finally {
            // Clean up temporary files
            if (file_exists($tempDir . '/custom/style/light.css')) {
                unlink($tempDir . '/custom/style/light.css');
            }
            if (is_dir($tempDir . '/custom/style')) {
                rmdir($tempDir . '/custom/style');
            }
            if (is_dir($tempDir . '/custom')) {
                rmdir($tempDir . '/custom');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }
}
