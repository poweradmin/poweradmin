<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HINFORecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the HINFORecordValidator
 */
class HINFORecordValidatorTest extends TestCase
{
    private HINFORecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new HINFORecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'Intel-x86 Linux';
        $name = 'host.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']); // HINFO always uses 0
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithQuotedData()
    {
        $content = '"Intel x86" "Windows 11"';
        $name = 'host.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']); // HINFO always uses 0
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'Intel-x86 Linux';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithEmptyContent()
    {
        $content = ''; // Empty content
        $name = 'host.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'Intel-x86 Linux';
        $name = 'host.example.com';
        $prio = '';
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'Intel-x86 Linux';
        $name = 'host.example.com';
        $prio = '';
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }

    public function testValidateWithInvalidQuoting()
    {
        $content = '"Intel x86 Linux'; // Missing closing quote
        $name = 'host.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidFormat()
    {
        $content = 'Intel-x86 Linux Ubuntu'; // Too many fields (3 instead of 2)
        $name = 'host.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithEmptyField()
    {
        $content = 'Intel-x86 ""'; // Empty OS field
        $name = 'host.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }
}
