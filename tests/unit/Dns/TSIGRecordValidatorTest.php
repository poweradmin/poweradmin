<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\TSIGRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

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

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);

        $this->assertEquals($name, $data['name']);
        $data = $result->getData();

        $this->assertEquals(0, $data['prio']);

        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithOtherData()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 10 MTIzNDU2Nzg5MA==';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithDifferentAlgorithm()
    {
        $content = 'hmac-md5.sig-alg.reg.int. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithHexMac()
    {
        $content = 'hmac-sha256. 1609459200 300 1234567890abcdef 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = 'invalid-algorithm 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTimestamp()
    {
        $content = 'hmac-sha256. invalid 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidFudge()
    {
        $content = 'hmac-sha256. 1609459200 invalid MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidMac()
    {
        $content = 'hmac-sha256. 1609459200 300 !@#$%^ 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidOriginalId()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 999999 0 0';  // ID too large
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidError()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 24 0';  // Error code too large
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidOtherLen()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 invalid';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidOtherData()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 10 !@#$%^';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithIncompleteParts()
    {
        $content = 'hmac-sha256. 1609459200 300';  // Missing mac, original-id, error, other-len
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0';
        $name = 'tsig-key.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals(86400, $data['ttl']);
    }
}
