<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\ARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * Tests for the ARecordValidator using ValidationResult pattern
 */
class ARecordValidatorTest extends TestCase
{
    private ARecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new ARecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '192.168.1.1';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid(), "A record validation should succeed for valid data");
        $data = $result->getData();

        $this->assertIsArray($data, "ValidationResult data should be an array");
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidIPv4()
    {
        $content = '300.168.1.1'; // Invalid IPv4
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid(), "A record validation should fail for invalid IPv4");
        $this->assertNotEmpty($result->getErrors(), "Should have error messages for invalid IPv4");
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '192.168.1.1';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid(), "A record validation should fail for invalid hostname");
        $this->assertNotEmpty($result->getErrors(), "Should have error messages for invalid hostname");
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '192.168.1.1';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid(), "A record validation should fail for invalid TTL");
        $this->assertNotEmpty($result->getErrors(), "Should have error messages for invalid TTL");
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '192.168.1.1';
        $name = 'host.example.com';
        $prio = 10; // Invalid priority for A record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid(), "A record validation should fail for invalid priority");
        $this->assertNotEmpty($result->getErrors(), "Should have error messages for invalid priority");
    }

    public function testValidateWithEmptyPriority()
    {
        $content = '192.168.1.1';
        $name = 'host.example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid(), "A record validation should succeed with empty priority");
        $data = $result->getData();
        $this->assertEquals(0, $data['prio'], "Empty priority should default to 0");
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '192.168.1.1';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid(), "A record validation should succeed with empty TTL");
        $data = $result->getData();
        $this->assertIsArray($data);
        $this->assertEquals(86400, $data['ttl'], "Empty TTL should use default value");
    }
}
