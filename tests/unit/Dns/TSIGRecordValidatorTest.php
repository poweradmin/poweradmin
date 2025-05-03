<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\TSIGRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Tests for the TSIGRecordValidator
 */
class TSIGRecordValidatorTest extends TestCase
{
    private TSIGRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new TSIGRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
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

    public function testValidateWithOtherData()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 10 MTIzNDU2Nzg5MA==';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithDifferentAlgorithm()
    {
        $content = 'hmac-md5.sig-alg.reg.int. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithHexMac()
    {
        $content = 'hmac-sha256. 1609459200 300 1234567890abcdef 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = 'invalid-algorithm 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTimestamp()
    {
        $content = 'hmac-sha256. invalid 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidFudge()
    {
        $content = 'hmac-sha256. 1609459200 invalid MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidMac()
    {
        $content = 'hmac-sha256. 1609459200 300 !@#$%^ 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidOriginalId()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 999999 0 0';  // ID too large
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidError()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 24 0';  // Error code too large
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidOtherLen()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 invalid';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidOtherData()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 10 !@#$%^';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithIncompleteParts()
    {
        $content = 'hmac-sha256. 1609459200 300';  // Missing mac, original-id, error, other-len
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }
}
