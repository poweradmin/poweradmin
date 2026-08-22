<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Covers the /api/health payload without touching a database or a PowerDNS server.
 */
class HealthControllerTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $settings
     */
    private function config(array $settings): ConfigurationInterface
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $settings[$group][$key] ?? $default
        );

        return $config;
    }

    public function testDisabledByDefaultAnswersNotFound(): void
    {
        $controller = new TestableHealthController($this->config([]));
        $response = $controller->buildResponse();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('checks', (string)$response->getContent());
    }

    public function testHealthyWhenDatabaseUpAndPdnsApiUnconfigured(): void
    {
        $controller = new TestableHealthController($this->config(['health' => ['enabled' => true]]));

        $response = $controller->buildResponse();
        $payload = json_decode((string)$response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('ok', $payload['checks']['database']);
        $this->assertSame('skipped', $payload['checks']['pdns_api']);
    }

    public function testSkippedPdnsApiNeverFailsOverallStatus(): void
    {
        $settings = [
            'health' => ['enabled' => true],
            'pdns_api' => ['url' => '', 'key' => ''],
        ];
        $controller = new TestableHealthController($this->config($settings));

        $this->assertSame(200, $controller->buildResponse()->getStatusCode());
    }

    /**
     * On the API backend an unconfigured PowerDNS API means no zone operation can
     * work, so it must not be waved through as 'skipped'.
     */
    public function testUnconfiguredPdnsApiIsDownOnTheApiBackend(): void
    {
        $settings = [
            'health' => ['enabled' => true],
            'dns' => ['backend' => 'api'],
            'pdns_api' => ['url' => '', 'key' => ''],
        ];
        $controller = new TestableHealthController($this->config($settings));

        $response = $controller->buildResponse();
        $payload = json_decode((string)$response->getContent(), true);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('down', $payload['checks']['pdns_api']);
    }

    public function testDatabaseDownReturns503(): void
    {
        $controller = new TestableHealthController($this->config(['health' => ['enabled' => true]]));
        $controller->databaseResult = 'down';

        $response = $controller->buildResponse();
        $payload = json_decode((string)$response->getContent(), true);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('error', $payload['status']);
        $this->assertSame('down', $payload['checks']['database']);
    }

    public function testPdnsApiDownReturns503(): void
    {
        $controller = new TestableHealthController($this->config(['health' => ['enabled' => true]]));
        $controller->pdnsResult = 'down';

        $response = $controller->buildResponse();
        $payload = json_decode((string)$response->getContent(), true);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('ok', $payload['checks']['database']);
        $this->assertSame('down', $payload['checks']['pdns_api']);
    }

    /**
     * The endpoint is unauthenticated, so it must not disclose anything about
     * the deployment - the project deliberately hides the release number from
     * anonymous visitors elsewhere too.
     */
    public function testResponseLeaksNoDeploymentDetail(): void
    {
        $settings = [
            'health' => ['enabled' => true],
            'database' => ['host' => 'db.internal.example', 'name' => 'secretdb', 'type' => 'mysql'],
            'pdns_api' => ['url' => 'http://pdns.internal.example:8081', 'key' => 'topsecret'],
        ];
        $controller = new TestableHealthController($this->config($settings));
        $controller->databaseResult = 'down';
        $controller->pdnsResult = 'down';

        $body = (string)$controller->buildResponse()->getContent();

        $this->assertStringNotContainsString('db.internal.example', $body);
        $this->assertStringNotContainsString('secretdb', $body);
        $this->assertStringNotContainsString('pdns.internal.example', $body);
        $this->assertStringNotContainsString('topsecret', $body);
        $this->assertStringNotContainsString(\Poweradmin\Version::VERSION, $body);
    }

    public function testResponseIsNotCacheable(): void
    {
        $controller = new TestableHealthController($this->config(['health' => ['enabled' => true]]));

        $this->assertStringContainsString(
            'no-store',
            (string)$controller->buildResponse()->headers->get('Cache-Control')
        );
    }
}
