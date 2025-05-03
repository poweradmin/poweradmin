<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\TKEYRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Tests for the TKEYRecordValidator
 */
class TKEYRecordValidatorTest extends TestCase
{
    private TKEYRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new TKEYRecordValidator($this->configMock);
    }

    public function testValidateWithValidDataUsingTimestamp()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
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

    public function testValidateWithValidDataUsingYYYYMMDDFormat()
    {
        $content = 'hmac-md5.example.com. 20210101000000 20211231235959 2 0 abcdef0123456789';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithInvalidAlgorithmName()
    {
        $content = '-invalid.algorithm. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidInceptionTime()
    {
        $content = 'hmac-sha256.example.com. invalid 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidExpirationTime()
    {
        $content = 'hmac-sha256.example.com. 1609459200 invalid 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidMode()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 6 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidError()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 24 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidKeyData()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 !@#$%^';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidFormat()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3';  // Missing error and key-data
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }
}
