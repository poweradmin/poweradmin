<?php

namespace unit\Api;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

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
        $config = $this->createMock(ConfigurationManager::class);
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
}
