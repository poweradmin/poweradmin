<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\PTRRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the PTRRecordValidator
 */
class PTRRecordValidatorTest extends TestCase
{
    private PTRRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturnMap([
                ['dns', 'top_level_tld_check', false],
                ['dns', 'strict_tld_check', false]
            ]);

        $this->validator = new PTRRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'host.example.com';
        $name = '1.0.168.192.in-addr.arpa';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']); // PTR always uses 0
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithInvalidContentHostname()
    {
        $content = '-invalid-hostname.example.com'; // Invalid hostname
        $name = '1.0.168.192.in-addr.arpa';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidNameHostname()
    {
        $content = 'host.example.com';
        $name = '-invalid.192.in-addr.arpa'; // Invalid reverse hostname
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'host.example.com';
        $name = '1.0.168.192.in-addr.arpa';
        $prio = '';
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'host.example.com';
        $name = '1.0.168.192.in-addr.arpa';
        $prio = '';
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }

    public function testValidateWithNonZeroPriority()
    {
        $content = 'host.example.com';
        $name = '1.0.168.192.in-addr.arpa';
        $prio = 10; // Non-zero priority (should be ignored for PTR records)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['prio']); // Priority should always be 0 for PTR
    }

    public function testValidateWithIPv6ReverseZone()
    {
        $content = 'host.example.com';
        $name = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
    }

    public function testValidateWithTrailingDot()
    {
        $content = 'host.example.com.'; // With trailing dot
        $name = '1.0.168.192.in-addr.arpa';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        // The hostname validator should normalize by removing the trailing dot
        $this->assertEquals('host.example.com', $result['content']);
    }
}
