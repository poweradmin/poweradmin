<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\ZONEMDRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Tests for the ZONEMDRecordValidator
 */
class ZONEMDRecordValidatorTest extends TestCase
{
    private ZONEMDRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new ZONEMDRecordValidator($this->configMock);
    }

    public function testValidateWithValidSHA384Data()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '2021121600 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
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

    public function testValidateWithValidSHA512Data()
    {
        // SHA-512 must be exactly 128 hex characters
        $content = '2021121600 1 2 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithInvalidSerial()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = 'invalid 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithTooLargeSerial()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '9999999999 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidScheme()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '2021121600 999 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidHashAlgorithm()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '2021121600 1 999 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }


    public function testValidateWithInvalidDigestHex()
    {
        $content = '2021121600 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4ez'; // 'z' is not hex
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '2021121600 1';  // Missing hash-algorithm and digest
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }


    public function testValidateWithInvalidTTL()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '2021121600 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        // SHA-384 must be exactly 96 hex characters
        $content = '2021121600 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
        $name = 'example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }
}
