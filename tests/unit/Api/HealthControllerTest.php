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

        $config->method('getGroup')->willReturnCallback(
            fn(string $group): array => $settings[$group] ?? []
        );

        $config->method('getAll')->willReturn($settings);

        return $config;
    }

    /**
     * NativeLogHandler calls error_log() directly, so redirecting the ini
     * destination is the only way to observe whether anything was emitted.
     */
    private function captureErrorLog(callable $emit): string
    {
        $file = (string)tempnam(sys_get_temp_dir(), 'pa-health-log');
        $previous = (string)ini_get('error_log');
        ini_set('error_log', $file);

        try {
            $emit();

            return (string)file_get_contents($file);
        } finally {
            ini_set('error_log', $previous);
            unlink($file);
        }
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

    /**
     * An unknown database.type fails the real probe without touching the network,
     * so the catch branch runs for real rather than through a stub.
     *
     * @param array<string, array<string, mixed>> $extra
     */
    private function controllerWithAFailingDatabase(array $extra = []): TestableHealthController
    {
        $controller = new TestableHealthController($this->config([
            'health' => ['enabled' => true],
            'database' => ['type' => 'no_such_driver'],
        ] + $extra));
        $controller->databaseResult = null;

        return $controller;
    }

    /**
     * logging.type is 'null' by default, so an operator who turned diagnostic
     * logging off does not get a line for every failed scrape.
     */
    public function testCheckFailuresAreSilentWhileDiagnosticLoggingIsDisabled(): void
    {
        $controller = $this->controllerWithAFailingDatabase();

        $emitted = $this->captureErrorLog(function () use ($controller) {
            $response = $controller->buildResponse();

            $this->assertSame(503, $response->getStatusCode());
            $this->assertStringContainsString('"database":"down"', (string)$response->getContent());
        });

        $this->assertSame('', $emitted);
    }

    public function testCheckFailuresReachTheErrorLogWhenNativeLoggingIsConfigured(): void
    {
        $controller = $this->controllerWithAFailingDatabase(['logging' => ['type' => 'native']]);

        $emitted = $this->captureErrorLog(fn() => $controller->buildResponse());

        $this->assertStringContainsString('Health check: database unreachable', $emitted);
        $this->assertStringContainsString('ERROR', $emitted);
    }

    /**
     * The message embeds the DSN, so it must reach the log and never the caller.
     */
    public function testTheFailureReasonNeverReachesTheResponseBody(): void
    {
        $controller = $this->controllerWithAFailingDatabase();

        $body = (string)$controller->buildResponse()->getContent();

        $this->assertStringNotContainsString('no_such_driver', $body);
        $this->assertStringNotContainsString('Unknown database type', $body);
    }
}
