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

    public function testNginxExampleChecksTheDenyRulesBeforeTheStaticAssetRule(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/nginx.conf.example');
        $this->assertNotFalse($content);
        preg_match_all('#location ~ \^(?:/poweradmin)?/\(config\|lib#', $content, $deny, PREG_OFFSET_CAPTURE);
        preg_match_all('#location ~\* \\\.\(js\|css#', $content, $static, PREG_OFFSET_CAPTURE);
        preg_match_all('#location ~ (?:\^/poweradmin)?/\\\\\. \{#', $content, $dot, PREG_OFFSET_CAPTURE);

        $this->assertCount(2, $deny[0]);
        $this->assertCount(2, $static[0]);
        $this->assertCount(2, $dot[0]);
        foreach ([0, 1] as $i) {
            $this->assertGreaterThan($deny[0][$i][1], $static[0][$i][1], 'the first matching regex location wins in nginx');
            $this->assertGreaterThan($dot[0][$i][1], $static[0][$i][1], 'the first matching regex location wins in nginx');
        }
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
