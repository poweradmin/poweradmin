<?php

namespace unit;

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

    /**
     * @dataProvider secureUrlProvider
     */
    public function testIsSecureUrl(string $url, bool $expected): void
    {
        $method = $this->reflection->getMethod('isSecureUrl');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $url);
        $this->assertSame($expected, $result);
    }

    public static function secureUrlProvider(): array
    {
        return [
            // Valid HTTP/HTTPS URLs
            ['http://example.com', true],
            ['https://example.com', true],
            ['http://localhost:8081/metrics', true],
            ['https://api.example.com/status', true],

            // Invalid schemes (security risk)
            ['file:///etc/passwd', false],
            ['ftp://example.com', false],
            ['javascript:alert(1)', false],
            ['data:text/plain;base64,SGVsbG8=', false],

            // Invalid URLs
            ['not-a-url', false],
            ['', false],
            ['http://', false],
            ['https://', false],

            // Edge cases
            ['http://127.0.0.1:8081', true],
            ['https://[::1]:8080', true],
        ];
    }
}
