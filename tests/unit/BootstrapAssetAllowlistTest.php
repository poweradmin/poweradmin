<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Only the Bootstrap dist and icon font directories are public; the rest of the
 * packages (package.json, sources, docs) stay behind the vendor block.
 */
class BootstrapAssetAllowlistTest extends TestCase
{
    private const ALLOWED = [
        'vendor/twbs/bootstrap/dist/css/bootstrap.min.css',
        'vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js',
        'vendor/twbs/bootstrap-icons/font/bootstrap-icons.css',
        'vendor/twbs/bootstrap-icons/font/fonts/bootstrap-icons.woff2',
    ];

    private const REFUSED = [
        'vendor/twbs/bootstrap/package.json',
        'vendor/twbs/bootstrap/composer.json',
        'vendor/twbs/bootstrap/scss/_variables.scss',
        'vendor/twbs/bootstrap-icons/package.json',
        'vendor/twbs/bootstrap-icons/icons/alarm.svg',
    ];

    public function testHtaccessAllowsOnlyTheDistDirectories(): void
    {
        $this->assertAllowlist($this->patternFrom('.htaccess', '/RewriteRule \^(vendor\/twbs\/\S+) - \[L\]/'));
    }

    public function testNginxExampleAllowsOnlyTheDistDirectories(): void
    {
        $this->assertAllowlist($this->patternFrom('nginx.conf.example', '/location ~ \^\/(vendor\/twbs\/\S+) \{/'));
    }

    private function patternFrom(string $file, string $extract): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $this->assertNotFalse($content);
        $this->assertSame(1, preg_match($extract, $content, $m), $file . ' has no twbs allow rule');

        return '#^' . $m[1] . '#';
    }

    private function assertAllowlist(string $pattern): void
    {
        foreach (self::ALLOWED as $path) {
            $this->assertSame(1, preg_match($pattern, $path), $path . ' must be served');
        }
        foreach (self::REFUSED as $path) {
            $this->assertSame(0, preg_match($pattern, $path), $path . ' must not be served');
        }
    }
}
