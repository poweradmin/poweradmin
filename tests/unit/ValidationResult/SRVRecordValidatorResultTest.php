<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for SRVRecordValidator using ValidationResult pattern
 */
class SRVRecordValidatorResultTest extends TestCase
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
        $content = '0 5 443 server.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(10, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidSrvName()
    {
        $content = '0 5 443 server.example.com';
        $name = 'invalid.example.com'; // Missing _service._proto format
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid service value in name field of SRV record', $result->getFirstError());
    }

    public function testValidateWithInvalidServicePrefix()
    {
        $content = '0 5 443 server.example.com';
        $name = 'sip._tcp.example.com'; // Missing underscore prefix for service
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('service', $result->getFirstError());
    }

    public function testValidateWithInvalidProtocolPrefix()
    {
        $content = '0 5 443 server.example.com';
        $name = '_sip.tcp.example.com'; // Missing underscore prefix for protocol
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('protocol', $result->getFirstError());
    }

    public function testValidateWithInvalidContentFormat()
    {
        $content = '0 5 server.example.com'; // Missing port
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('priority, weight, port and target', $result->getFirstError());
    }

    public function testValidateWithInvalidPriorityInContent()
    {
        $content = '-1 5 443 server.example.com'; // Invalid priority
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    public function testValidateWithInvalidWeight()
    {
        $content = '0 -5 443 server.example.com'; // Invalid weight
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('weight', $result->getFirstError());
    }

    public function testValidateWithInvalidPort()
    {
        $content = '0 5 -443 server.example.com'; // Invalid port
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('port', $result->getFirstError());
    }

    public function testValidateWithInvalidTarget()
    {
        $content = '0 5 443 -server.example.com'; // Invalid hostname
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('target', $result->getFirstError());
    }

    public function testValidateWithEmptyTarget()
    {
        $content = '0 5 443 '; // Empty target
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithEmptyPriorityUsingDefault()
    {
        $content = '0 5 443 server.example.com';
        $name = '_sip._tcp.example.com';
        $prio = ''; // Empty priority should use default of 10
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(10, $data['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '0 5 443 server.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
