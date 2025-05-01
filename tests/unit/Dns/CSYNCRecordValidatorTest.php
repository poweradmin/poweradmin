<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\CSYNCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Tests for the CSYNCRecordValidator
 */
class CSYNCRecordValidatorTest extends TestCase
{
    private CSYNCRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new CSYNCRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithInvalidSOASerial()
    {
        $content = '-1 1 A NS AAAA'; // Negative SOA Serial
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithOverflowSOASerial()
    {
        $content = '4294967296 1 A NS AAAA'; // SOA Serial > 32-bit unsigned int max
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidFlags()
    {
        $content = '1234567890 4 A NS AAAA'; // Flag value > 3
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithNegativeFlags()
    {
        $content = '1234567890 -1 A NS AAAA'; // Negative flag value
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithNoRecordTypes()
    {
        $content = '1234567890 1'; // No record types specified
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidRecordType()
    {
        $content = '1234567890 1 A NS INVALID'; // Invalid record type
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = 'host.example.com';
        $prio = 10; // Invalid priority for CSYNC record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithEmptyPriority()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = 'host.example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }

    public function testValidateWithMultipleValidRecordTypes()
    {
        $content = '1234567890 1 A NS AAAA MX CNAME TXT SRV PTR DNAME';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testIsValidCSYNCContent()
    {
        $this->assertTrue($this->validator->isValidCSYNCContent('1234567890 1 A NS AAAA'));
        $this->assertTrue($this->validator->isValidCSYNCContent('1 0 A'));
        $this->assertTrue($this->validator->isValidCSYNCContent('1234567890 3 A NS AAAA MX TXT'));
        $this->assertTrue($this->validator->isValidCSYNCContent('42 2 NS'));
    }

    public function testIsValidCSYNCContentWithInvalidInputs()
    {
        $this->assertFalse($this->validator->isValidCSYNCContent('-1 1 A')); // Negative SOA Serial
        $this->assertFalse($this->validator->isValidCSYNCContent('1234567890 4 A')); // Flag > 3
        $this->assertFalse($this->validator->isValidCSYNCContent('1234567890 1')); // No record types
        $this->assertFalse($this->validator->isValidCSYNCContent('1234567890 1 INVALID')); // Invalid record type
        $this->assertFalse($this->validator->isValidCSYNCContent('abc 1 A')); // Non-numeric SOA Serial
        $this->assertFalse($this->validator->isValidCSYNCContent('1234567890 abc A')); // Non-numeric Flag
    }
}
