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
}
