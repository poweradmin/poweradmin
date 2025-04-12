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
}
