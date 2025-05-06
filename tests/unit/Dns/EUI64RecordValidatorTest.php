<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\EUI64RecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the EUI64RecordValidator with ValidationResult pattern
 */
class EUI64RecordValidatorTest extends TestCase
{
    private EUI64RecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new EUI64RecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '00-11-22-33-44-55-66-77';  // Valid EUI-64 format
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

    public function testValidateWithValidUppercaseHexData()
    {
        $content = '00-1A-2B-3C-4D-5E-6F-A7';  // Valid EUI-64 with uppercase hex
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithInvalidEUI64Format()
    {
        $content = '00:11:22:33:44:55:66:77';  // Using colons instead of hyphens
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('EUI-64 address', $result->getFirstError());
    }

    public function testValidateWithInvalidEUI64Values()
    {
        $content = '00-11-22-33-44-55-66-GG';  // 'GG' is not a valid hex value
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('EUI-64 address', $result->getFirstError());
    }

    public function testValidateWithInvalidEUI64Length()
    {
        $content = '00-11-22-33-44-55-66';  // Too short
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('EUI-64 address', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '00-11-22-33-44-55-66-77';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '00-11-22-33-44-55-66-77';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '00-11-22-33-44-55-66-77';
        $name = 'host.example.com';
        $prio = 10;  // Invalid priority for EUI64 records
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field for EUI64 records', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '00-11-22-33-44-55-66-77';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
