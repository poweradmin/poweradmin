<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shipped web server configs must allow every method the API uses (PATCH
 * included) and .htaccess must answer the CORS preflight itself.
 */
class CorsConfigExamplesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function configFiles(): array
    {
        return [
            'nginx' => ['nginx.conf.example'],
            'caddy' => ['caddy.conf.example'],
            'dockerfile' => ['Dockerfile'],
            'htaccess' => ['.htaccess'],
        ];
    }

    #[DataProvider('configFiles')]
    public function testAllowMethodsIncludesPatch(string $file): void
    {
        $content = $this->read($file);

        $count = preg_match_all('/Access-Control-Allow-Methods\s+"([^"]+)"/', $content, $m);
        $this->assertGreaterThan(0, $count, $file . ' declares no Allow-Methods header');
        foreach ($m[1] as $methods) {
            $this->assertContains('PATCH', array_map('trim', explode(',', $methods)), $file . ': ' . $methods);
        }
    }

    public function testHtaccessAnswersApiPreflightBeforeTheExistingFileShortcut(): void
    {
        $content = $this->read('.htaccess');

        $preflight = strpos($content, 'RewriteRule ^api(/|$) - [R=204,L]');
        $this->assertNotFalse($preflight);
        $this->assertStringContainsString('RewriteCond %{REQUEST_METHOD} =OPTIONS', $content);
        $this->assertLessThan(strpos($content, 'RewriteRule ^(.*)$ index.php'), $preflight);
    }

    public function testHtaccessCorsHeadersApplyToTheBodylessPreflightResponse(): void
    {
        $content = $this->read('.htaccess');

        $this->assertStringNotContainsString('<FilesMatch "^index', $content, 'a rewrite-generated 204 never reaches index.php');
        $this->assertMatchesRegularExpression('/Header always set Access-Control-Allow-Origin "\*" env=CORS/', $content);
        $this->assertMatchesRegularExpression('/Header always set Access-Control-Allow-Origin "\*" env=REDIRECT_CORS/', $content);
        $this->assertDoesNotMatchRegularExpression('/^\s*Header set Access-Control-/m', $content);
    }

    private function read(string $file): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $this->assertNotFalse($content);

        return $content;
    }
}
