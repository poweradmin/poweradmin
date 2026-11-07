<?php

namespace Poweradmin\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;

#[BackupGlobals(true)]
class DocsControllerTest extends TestCase
{
    private TestableDocsController $controller;

    protected function setUp(): void
    {
        // Create testable controller instance
        $this->controller = new TestableDocsController();
    }

    private function withConfig(string $applicationUrl, string $baseUrlPrefix = ''): void
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            function (string $group, string $key) use ($applicationUrl, $baseUrlPrefix) {
                if ($group !== 'interface') {
                    return '';
                }

                return match ($key) {
                    'application_url' => $applicationUrl,
                    'base_url_prefix' => $baseUrlPrefix,
                    default => '',
                };
            }
        );

        $this->controller->setConfig($config);
    }

    public function testUsesApplicationUrlWhenConfigured(): void
    {
        $_SERVER['SERVER_NAME'] = 'evil.attacker.test';
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';
        $this->withConfig('https://dns.example.com/');

        $this->assertEquals('https://dns.example.com', $this->controller->getDocsBaseUrlPublic());
    }

    public function testIgnoresForgedHostWhenApplicationUrlIsUnset(): void
    {
        // SERVER_NAME follows the client Host header under FrankenPHP and Apache
        // defaults, so the docs page falls back to a relative spec URL instead
        $_SERVER['SERVER_NAME'] = 'evil.attacker.test';
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';
        $this->withConfig('');

        $baseUrl = $this->controller->getDocsBaseUrlPublic();

        $this->assertEquals('', $baseUrl);
        $this->assertStringNotContainsString('evil.attacker.test', $baseUrl);
    }

    public function testFallsBackToBaseUrlPrefixWhenApplicationUrlIsUnset(): void
    {
        $_SERVER['SERVER_NAME'] = 'evil.attacker.test';
        $this->withConfig('', '/poweradmin');

        $this->assertEquals('/poweradmin', $this->controller->getDocsBaseUrlPublic());
    }

    public function testSwaggerUiLoadsOnlyBundledAssets(): void
    {
        $this->withConfig('', '/poweradmin');

        $html = $this->controller->generateSwaggerUIPublic();

        preg_match_all('/(?:src|href)="([^"]+)"/', $html, $matches);
        $this->assertCount(3, $matches[1]);
        foreach ($matches[1] as $url) {
            $this->assertStringStartsWith('/poweradmin/assets/swagger-ui/', $url);
            $file = dirname(__DIR__, 3) . '/' . substr(strtok($url, '?'), strlen('/poweradmin/'));
            $this->assertFileExists($file);
        }
        $this->assertStringNotContainsString('unpkg.com', $html);
    }

    public function testSwaggerUiAssetsFollowTheApplicationUrl(): void
    {
        // A proxy that strips its path prefix leaves base_url_prefix empty; the public URL is in application_url
        $this->withConfig('https://gw.example.com/poweradmin', '');

        $html = $this->controller->generateSwaggerUIPublic();

        $this->assertStringContainsString('href="https://gw.example.com/poweradmin/assets/swagger-ui/swagger-ui.css?v=', $html);
        $this->assertStringContainsString('src="https://gw.example.com/poweradmin/assets/swagger-ui/swagger-ui-bundle.js?v=', $html);
    }
}
