<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
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

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(10, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithInvalidSrvName()
    {
        $content = '10 20 5060 sip.example.com';
        $name = 'invalid.example.com'; // Missing _service._protocol format
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidSrvNameService()
    {
        $content = '10 20 5060 sip.example.com';
        $name = 'sip._tcp.example.com'; // Missing _ prefix for service
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidSrvNameProtocol()
    {
        $content = '10 20 5060 sip.example.com';
        $name = '_sip.tcp.example.com'; // Missing _ prefix for protocol
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidContent()
    {
        $content = '10 20 sip.example.com'; // Missing port field
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidContentPriority()
    {
        $content = 'invalid 20 5060 sip.example.com'; // Non-numeric priority
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidContentWeight()
    {
        $content = '10 invalid 5060 sip.example.com'; // Non-numeric weight
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidContentPort()
    {
        $content = '10 20 invalid sip.example.com'; // Non-numeric port
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidContentTarget()
    {
        $content = '10 20 5060 -invalid-.example.com'; // Invalid hostname
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithEmptyPriority()
    {
        $content = '10 20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = ''; // Empty priority should use default (10 for SRV records)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(10, $result['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }
}
