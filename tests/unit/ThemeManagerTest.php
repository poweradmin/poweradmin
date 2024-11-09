<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Service\ThemeManager;

class ThemeManagerTest extends TestCase
{
    private string $testStyleDir;

    private function setCookieTheme(string $theme): void
    {
        $_COOKIE['theme'] = $theme;
    }

    protected function setUp(): void
    {
        $this->testStyleDir = dirname(__DIR__, 2) . '/style/';
    }

    public function testDefaultTheme()
    {
        $themeManager = new ThemeManager();

        $this->assertEquals('ignite', $themeManager->getSelectedTheme());
    }

    public function testCustomTheme()
    {
        $themeManager = new ThemeManager('spark', $this->testStyleDir);

        $this->assertEquals('spark', $themeManager->getSelectedTheme());
    }

    public function testInvalidCustomTheme()
    {
        $themeManager = new ThemeManager('nonexistent', $this->testStyleDir);

        $this->assertEquals('ignite', $themeManager->getSelectedTheme());
    }

    public function testInvalidStyleDir()
    {
        $themeManager = new ThemeManager('ignite', 'nonexistent_dir');

        $this->assertEquals('ignite', $themeManager->getSelectedTheme());
    }

    public function testThemeFromCookie()
    {
        $this->setCookieTheme('spark');
        $themeManager = new ThemeManager('ignite', $this->testStyleDir);

        $this->assertEquals('spark', $themeManager->getSelectedTheme());
    }

    public function testInvalidThemeFromCookie()
    {
        $this->setCookieTheme('nonexistent');
        $themeManager = new ThemeManager('ignite', $this->testStyleDir);

        $this->assertEquals('ignite', $themeManager->getSelectedTheme());
    }

    public function testValidThemeFromCookieWithCustomTheme()
    {
        $this->setCookieTheme('spark');
        $themeManager = new ThemeManager('ignite', $this->testStyleDir);

        $this->assertEquals('spark', $themeManager->getSelectedTheme());
    }

    public function testInvalidThemeFromCookieWithCustomTheme()
    {
        $this->setCookieTheme('nonexistent');
        $themeManager = new ThemeManager('spark', $this->testStyleDir);

        $this->assertEquals('spark', $themeManager->getSelectedTheme());
    }
}
