<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\PowerdnsStatusService;
use ReflectionClass;

class PowerdnsStatusServiceTest extends TestCase
{
    private PowerdnsStatusService $service;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->service = new PowerdnsStatusService();
        $this->reflection = new ReflectionClass($this->service);
    }

    #[DataProvider('displayNameProvider')]
    public function testSanitizeDisplayName($input, string $expected): void
    {
        $method = $this->reflection->getMethod('sanitizeDisplayName');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $input);
        $this->assertSame($expected, $result);
    }

    public static function displayNameProvider(): array
    {
        return [
            // Valid strings
            ['PowerDNS', 'PowerDNS'],
            ['My DNS Server', 'My DNS Server'],
            ['DNS-01', 'DNS-01'],

            // Empty/null values should return default
            ['', 'PowerDNS'],
            [null, 'PowerDNS'],
            [false, 'PowerDNS'],
            [0, 'PowerDNS'],
            [[], 'PowerDNS'],

            // Whitespace handling
            ['  PowerDNS  ', 'PowerDNS'],
            ["\t\nDNS Server\r\n", 'DNS Server'],
            ['   ', 'PowerDNS'], // Only whitespace

            // Long strings should be truncated
            [str_repeat('A', 100), str_repeat('A', 47) . '...'],
            [str_repeat('DNS', 20), str_repeat('DNS', 15) . 'DN...'], // 50+ chars

            // Edge cases
            ['A', 'A'], // Single character
            ['🚀 PowerDNS 🔥', '🚀 PowerDNS 🔥'], // Unicode/emojis
            ['Power&DNS <Test>', 'Power&DNS <Test>'], // Special characters (no XSS filtering in sanitize method)
        ];
    }

    private function setApiUrl(object $service, string $url): void
    {
        $property = (new ReflectionClass(PowerdnsStatusService::class))->getProperty('apiUrl');
        $property->setAccessible(true);
        $property->setValue($service, $url);
    }

    private function invokePrivate(object $service, string $method, array $args = []): mixed
    {
        $reflectionMethod = (new ReflectionClass(PowerdnsStatusService::class))->getMethod($method);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($service, $args);
    }

    #[DataProvider('metricsUrlProvider')]
    public function testBuildMetricsUrlPreservesPortAndPathPrefix(string $apiUrl, string $expected): void
    {
        $this->setApiUrl($this->service, $apiUrl);

        $this->assertSame($expected, $this->invokePrivate($this->service, 'buildMetricsUrl'));
    }

    public static function metricsUrlProvider(): array
    {
        return [
            'explicit port' => ['http://127.0.0.1:8081', 'http://127.0.0.1:8081/metrics'],
            'trailing slash' => ['http://127.0.0.1:8081/', 'http://127.0.0.1:8081/metrics'],
            // Regression: the old parse_url rebuild forced :8081 and dropped the prefix.
            'implicit https port' => ['https://pdns.example.com', 'https://pdns.example.com/metrics'],
            'reverse proxy prefix' => ['https://pdns.example.com/api', 'https://pdns.example.com/api/metrics'],
        ];
    }

    #[DataProvider('metricsUrlValidationProvider')]
    public function testIsValidMetricsUrl(string $apiUrl, string $candidate, bool $expected): void
    {
        $this->setApiUrl($this->service, $apiUrl);

        $this->assertSame($expected, $this->invokePrivate($this->service, 'isValidMetricsUrl', [$candidate]));
    }

    public static function metricsUrlValidationProvider(): array
    {
        return [
            'derived url accepted' => ['http://127.0.0.1:8081', 'http://127.0.0.1:8081/metrics', true],
            'derived prefixed url accepted' => ['https://pdns.example.com/api', 'https://pdns.example.com/api/metrics', true],
            'implicit port accepted' => ['https://pdns.example.com', 'https://pdns.example.com/metrics', true],
            'file scheme rejected' => ['http://127.0.0.1:8081', 'file:///etc/passwd', false],
            'other host rejected' => ['http://127.0.0.1:8081', 'http://evil.test/metrics', false],
            'other port rejected' => ['https://pdns.example.com', 'https://pdns.example.com:9999/metrics', false],
            'escaping the prefix rejected' => ['https://pdns.example.com/api', 'https://pdns.example.com/metrics', false],
            'path traversal rejected' => ['https://pdns.example.com/api', 'https://pdns.example.com/api/../../metrics', false],
        ];
    }

    /**
     * @param PowerdnsStatusService $service
     */
    private function enableApi(object $service): void
    {
        $property = (new ReflectionClass(PowerdnsStatusService::class))->getProperty('apiEnabled');
        $property->setAccessible(true);
        $property->setValue($service, true);
    }

    /**
     * @return array<int, string>
     */
    private static function autoprimaries(int $count): array
    {
        $servers = [];
        for ($i = 1; $i <= $count; $i++) {
            $servers[] = '192.0.2.' . $i;
        }
        return $servers;
    }

    public function testFastAutoprimaryProbesAllRunWithinBudget(): void
    {
        $service = new class extends PowerdnsStatusService {
            public int $probes = 0;

            protected function probeHost(string $host): ?string
            {
                $this->probes++;
                return null;
            }
        };
        $this->enableApi($service);

        $results = $service->checkSlaveServerStatus(self::autoprimaries(25));

        $this->assertCount(25, $results);
        $this->assertSame(25, $service->probes, 'cheap probes must not be truncated');
        $this->assertSame('ok', $results['192.0.2.25']['status']);
    }

    public function testSlowAutoprimaryProbesStopAtTheBudgetAndRestAreSkipped(): void
    {
        $service = new class extends PowerdnsStatusService {
            public int $probes = 0;

            protected function probeHost(string $host): string
            {
                $this->probes++;
                usleep(600_000);
                return 'Connection timed out';
            }
        };
        $this->enableApi($service);

        $results = $service->checkSlaveServerStatus(self::autoprimaries(25));

        $this->assertCount(25, $results, 'every host is still reported');
        $this->assertLessThan(25, $service->probes, 'probing stops once the budget is spent');
        $this->assertSame('unreachable', $results['192.0.2.1']['status']);
        $this->assertSame('skipped', $results['192.0.2.25']['status']);
        $this->assertSame('', $results['192.0.2.25']['lastChecked'], 'a skipped host was never checked');
    }
}
