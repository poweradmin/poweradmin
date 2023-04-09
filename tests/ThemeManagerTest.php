<?php

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\UI\Web\ThemeManager;

class ThemeManagerTest extends TestCase
{
    private string $testStyleDir;

    protected function setUp(): void
    {
        $this->testStyleDir = dirname(__DIR__) . '/style/';
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
}

