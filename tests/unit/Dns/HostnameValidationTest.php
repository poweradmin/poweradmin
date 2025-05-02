<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Tests for hostname validation logic
 */
class HostnameValidationTest extends BaseDnsTest
{
    public function testHostnameValidation()
    {
        // Configure config mock
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns') {
                    if ($key === 'top_level_tld_check') {
                        return false;
                    }
                    if ($key === 'strict_tld_check') {
                        return false;
                    }
                }
                return null;
            });

        // Create validator instance
        $validator = new HostnameValidator($configMock);

        // Test valid hostname without wildcard
        $result = $validator->isValidHostnameFqdn('example.com', 0);
        $this->assertIsArray($result);
        $this->assertEquals(['hostname' => 'example.com'], $result);

        // Test valid hostname with wildcard
        $result = $validator->isValidHostnameFqdn('*.example.com', 1);
        $this->assertIsArray($result);
        $this->assertEquals(['hostname' => '*.example.com'], $result);

        // Test invalid hostname
        $this->assertFalse($validator->isValidHostnameFqdn('-invalid.com', 0));
    }

    /**
     * Test endsWith static method
     */
    public function testEndsWith()
    {
        // Test cases for endsWith method
        $cases = [
            // Basic matching
            ['needle' => 'com', 'haystack' => 'example.com', 'expected' => true],
            ['needle' => 'example.com', 'haystack' => 'example.com', 'expected' => true],
            ['needle' => '', 'haystack' => 'example.com', 'expected' => true],
            ['needle' => 'test', 'haystack' => 'example.com', 'expected' => false],
            ['needle' => 'com.example', 'haystack' => 'example.com', 'expected' => false],

            // Case sensitivity
            ['needle' => 'COM', 'haystack' => 'example.com', 'expected' => false],
            ['needle' => 'Com', 'haystack' => 'example.com', 'expected' => false],

            // Empty strings
            ['needle' => '', 'haystack' => '', 'expected' => true],
            ['needle' => 'com', 'haystack' => '', 'expected' => false],

            // Special characters
            ['needle' => '@#$', 'haystack' => 'test@#$', 'expected' => true],
            ['needle' => '123', 'haystack' => 'domain123', 'expected' => true],
            ['needle' => '.', 'haystack' => 'example.', 'expected' => true],

            // Multi-byte characters
            ['needle' => 'ñ', 'haystack' => 'español.españ', 'expected' => true],
            ['needle' => '中国', 'haystack' => 'example.中国', 'expected' => true],
            ['needle' => 'россия', 'haystack' => 'пример.россия', 'expected' => true],

            // DNS domain scenarios
            ['needle' => 'example.com', 'haystack' => 'subdomain.example.com', 'expected' => true],
            ['needle' => 'co.uk', 'haystack' => 'example.co.uk', 'expected' => true],
            ['needle' => 'example.com.', 'haystack' => 'subdomain.example.com.', 'expected' => true],
            ['needle' => 'example.org', 'haystack' => 'example.com', 'expected' => false],

            // Similar endings
            ['needle' => 'comx', 'haystack' => 'example.com', 'expected' => false],
            ['needle' => 'xcom', 'haystack' => 'example.com', 'expected' => false],
            ['needle' => 'co', 'haystack' => 'example.com', 'expected' => false],

            // Length edge cases
            ['needle' => 'longer.example.com', 'haystack' => 'example.com', 'expected' => false],
            ['needle' => 'abcdefghijklmnopqrstuvwxyz', 'haystack' => 'xyz', 'expected' => false]
        ];

        foreach ($cases as $case) {
            $this->assertEquals(
                $case['expected'],
                HostnameValidator::endsWith($case['needle'], $case['haystack']),
                "Failed assertion for needle: {$case['needle']}, haystack: {$case['haystack']}"
            );
        }
    }
}
