<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the HostnameValidator service
 */
class HostnameValidatorTest extends TestCase
{
    private HostnameValidator $validator;

    protected function setUp(): void
    {
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

        $this->validator = new HostnameValidator($configMock);
    }

    public function testIsValidHostnameFqdn()
    {
        // Valid hostname
        $hostname = 'example.com';
        $result = $this->validator->isValidHostnameFqdn($hostname, 0);
        $this->assertIsArray($result);
        $this->assertEquals(['hostname' => 'example.com'], $result);

        // Invalid hostnames
        $hostname = '-example.com'; // Starts with dash
        $this->assertFalse($this->validator->isValidHostnameFqdn($hostname, 0));

        $hostname = 'example-.com'; // Ends with dash
        $this->assertFalse($this->validator->isValidHostnameFqdn($hostname, 0));

        $hostname = 'exam&ple.com'; // Invalid character
        $this->assertFalse($this->validator->isValidHostnameFqdn($hostname, 0));

        $hostname = str_repeat('a', 64) . '.example.com'; // Label too long (>63 chars)
        $this->assertFalse($this->validator->isValidHostnameFqdn($hostname, 0));

        $hostname = str_repeat('a', 254); // Full name too long (>253 chars)
        $this->assertFalse($this->validator->isValidHostnameFqdn($hostname, 0));
    }

    /**
     * Test the normalizeRecordName function
     */
    public function testNormalizeRecordName()
    {
        // Test case the: Name without zone suffix
        $name = "www";
        $zone = "example.com";
        $expected = "www.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Name already has zone suffix
        $name = "mail.example.com";
        $zone = "example.com";
        $expected = "mail.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Empty name should return zone
        $name = "";
        $zone = "example.com";
        $expected = "example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Case-insensitive matching
        $name = "SUB.EXAMPLE.COM";
        $zone = "example.com";
        $expected = "SUB.EXAMPLE.COM";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Name is @ sign (should be transformed)
        $name = "@";
        $zone = "example.com";
        $expected = "@.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Subdomain of zone
        $name = "test.sub";
        $zone = "example.com";
        $expected = "test.sub.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));
    }

    /**
     * Test the endsWith static function
     */
    public function testEndsWith()
    {
        $this->assertTrue(HostnameValidator::endsWith('com', 'example.com'));
        $this->assertTrue(HostnameValidator::endsWith('example.com', 'example.com'));
        $this->assertTrue(HostnameValidator::endsWith('', 'example.com'));

        $this->assertFalse(HostnameValidator::endsWith('test', 'example.com'));
        $this->assertFalse(HostnameValidator::endsWith('com.example', 'example.com'));
        $this->assertFalse(HostnameValidator::endsWith('example.com.org', 'example.com'));
    }
}
