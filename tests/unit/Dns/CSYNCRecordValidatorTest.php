<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\CSYNCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

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

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidSOASerial()
    {
        $content = '-1 1 A NS AAAA'; // Negative SOA Serial
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('SOA Serial', $result->getFirstError());
    }

    public function testValidateWithOverflowSOASerial()
    {
        $content = '4294967296 1 A NS AAAA'; // SOA Serial > 32-bit unsigned int max
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('SOA Serial', $result->getFirstError());
    }

    public function testValidateWithInvalidFlags()
    {
        $content = '1234567890 4 A NS AAAA'; // Flag value > 3
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Flags', $result->getFirstError());
    }

    public function testValidateWithNegativeFlags()
    {
        $content = '1234567890 -1 A NS AAAA'; // Negative flag value
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Flags', $result->getFirstError());
    }

    public function testValidateWithNoRecordTypes()
    {
        $content = '1234567890 1'; // No record types specified
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must specify at least one record type', $result->getFirstError());
    }

    public function testValidateWithInvalidRecordType()
    {
        $content = '1234567890 1 A NS INVALID'; // Invalid record type
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid Type', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL field', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = 'host.example.com';
        $prio = 10; // Invalid priority for CSYNC record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority field', $result->getFirstError());
    }

    public function testValidateWithEmptyPriority()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = 'host.example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '1234567890 1 A NS AAAA';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithMultipleValidRecordTypes()
    {
        $content = '1234567890 1 A NS AAAA MX CNAME TXT SRV PTR DNAME';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateCSYNCContent()
    {
        $validContent = '1234567890 1 A NS AAAA';
        $result = $this->validator->validateCSYNCContent($validContent);
        $this->assertTrue($result->isValid());

        $validContent = '1 0 A';
        $result = $this->validator->validateCSYNCContent($validContent);
        $this->assertTrue($result->isValid());

        $validContent = '1234567890 3 A NS AAAA MX TXT';
        $result = $this->validator->validateCSYNCContent($validContent);
        $this->assertTrue($result->isValid());

        $validContent = '42 2 NS';
        $result = $this->validator->validateCSYNCContent($validContent);
        $this->assertTrue($result->isValid());
    }

    public function testValidateCSYNCContentWithInvalidInputs()
    {
        // Negative SOA Serial
        $result = $this->validator->validateCSYNCContent('-1 1 A');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('SOA Serial', $result->getFirstError());

        // Flag > 3
        $result = $this->validator->validateCSYNCContent('1234567890 4 A');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Flags', $result->getFirstError());

        // No record types
        $result = $this->validator->validateCSYNCContent('1234567890 1');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must specify at least one record type', $result->getFirstError());

        // Invalid record type
        $result = $this->validator->validateCSYNCContent('1234567890 1 INVALID');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid Type', $result->getFirstError());
    }
}
