<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the SRVRecordValidator
 */
class SRVRecordValidatorTest extends TestCase
{
    private SRVRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new SRVRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '10 20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(10, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidSrvName()
    {
        $content = '10 20 5060 sip.example.com';
        $name = 'invalid.example.com'; // Missing _service._protocol format
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid service value in name field of SRV record', $result->getFirstError());
    }

    public function testValidateWithInvalidSrvNameService()
    {
        $content = '10 20 5060 sip.example.com';
        $name = 'sip._tcp.example.com'; // Missing _ prefix for service
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('service value', $result->getFirstError());
    }

    public function testValidateWithInvalidSrvNameProtocol()
    {
        $content = '10 20 5060 sip.example.com';
        $name = '_sip.tcp.example.com'; // Missing _ prefix for protocol
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('protocol value', $result->getFirstError());
    }

    public function testValidateWithInvalidContent()
    {
        $content = '10 20 sip.example.com'; // Missing port field
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority, weight, port and target', $result->getFirstError());
    }

    public function testValidateWithInvalidContentPriority()
    {
        $content = 'invalid 20 5060 sip.example.com'; // Non-numeric priority
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority field', $result->getFirstError());
    }

    public function testValidateWithInvalidContentWeight()
    {
        $content = '10 invalid 5060 sip.example.com'; // Non-numeric weight
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('weight field', $result->getFirstError());
    }

    public function testValidateWithInvalidContentPort()
    {
        $content = '10 20 invalid sip.example.com'; // Non-numeric port
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('port field', $result->getFirstError());
    }

    public function testValidateWithInvalidContentTarget()
    {
        $content = '10 20 5060 -invalid-.example.com'; // Invalid hostname
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('target', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL field', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '10 20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 'invalid'; // Invalid priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority field', $result->getFirstError());
    }

    public function testValidateWithEmptyPriority()
    {
        $content = '10 20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = ''; // Empty priority should use default (10 for SRV records)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(10, $data['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateSrvName()
    {
        // Using reflection to access private method
        $method = new \ReflectionMethod(SRVRecordValidator::class, 'validateSrvName');
        $method->setAccessible(true);

        // Test valid name
        $validResult = $method->invoke($this->validator, '_sip._tcp.example.com');
        $this->assertTrue($validResult->isValid());
        $this->assertEquals(['name' => '_sip._tcp.example.com'], $validResult->getData());

        // Test invalid name
        $invalidResult = $method->invoke($this->validator, 'invalid.example.com');
        $this->assertFalse($invalidResult->isValid());
    }

    public function testValidateSrvContent()
    {
        // Using reflection to access private method
        $method = new \ReflectionMethod(SRVRecordValidator::class, 'validateSrvContent');
        $method->setAccessible(true);

        // Test valid content
        $validResult = $method->invoke($this->validator, '10 20 5060 sip.example.com', '_sip._tcp.example.com');
        $this->assertTrue($validResult->isValid());
        $this->assertEquals(['content' => '10 20 5060 sip.example.com'], $validResult->getData());

        // Test invalid content
        $invalidResult = $method->invoke($this->validator, '10 20 invalid', '_sip._tcp.example.com');
        $this->assertFalse($invalidResult->isValid());
    }
}
