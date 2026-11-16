<?php

namespace unit;

use PHPUnit\Framework\TestCase;

/**
 * Release archives must not carry development files, but the web migrations page
 * still needs phinx.php and db/migrations at runtime.
 */
class GitattributesExportIgnoreTest extends TestCase
{
    /** @var array<int, string> */
    private array $ignored;

    protected function setUp(): void
    {
        $lines = file(dirname(__DIR__, 2) . '/.gitattributes', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);
        $this->ignored = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\S+)\s+export-ignore$/', $line, $m)) {
                $this->ignored[] = $m[1];
            }
        }
    }

    public function testDevelopmentFilesAreExcludedFromArchives(): void
    {
        foreach (['.devcontainer/', '.github/', 'tests/', 'playwright/', 'psalm.xml', 'phpunit.xml.dist', 'DEVELOPMENT.md'] as $path) {
            $this->assertContains($path, $this->ignored, $path . ' must be export-ignored');
        }
    }

    public function testRuntimeFilesStayInArchives(): void
    {
        foreach (['phinx.php', 'db/', 'db', 'lib/', 'sql/', 'composer.json', 'Dockerfile', 'nginx.conf.example', '.htaccess'] as $path) {
            $this->assertNotContains($path, $this->ignored, $path . ' is needed at runtime');
        }
    }
}
