<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\TKEYRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;

/**
 * Tests for the TKEYRecordValidator
 */
class TKEYRecordValidatorTest extends TestCase
{
    private TKEYRecordValidator $validator;
    private ConfigurationManager $configMock;
    private HostnameValidator $hostnameValidatorMock;
    private TTLValidator $ttlValidatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        // Mock the validators we need
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if (strpos($hostname, '-invalid') !== false) {
                    return ValidationResult::failure('Invalid hostname');
                }
                return ValidationResult::success(['hostname' => $hostname]);
            });

        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->ttlValidatorMock->method('validate')
            ->willReturnCallback(function ($ttl, $defaultTTL) {
                if ($ttl === -1) {
                    return ValidationResult::failure('Invalid TTL value');
                }
                if (empty($ttl)) {
                    return ValidationResult::success($defaultTTL);
                }
                return ValidationResult::success($ttl);
            });

        // Create the validator instance
        $this->validator = new TKEYRecordValidator($this->configMock);

        // Inject the mock hostname validator
        $reflectionProperty = new ReflectionProperty(TKEYRecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->hostnameValidatorMock);

        // Inject the mock TTL validator
        $reflectionProperty = new ReflectionProperty(TKEYRecordValidator::class, 'ttlValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->ttlValidatorMock);
    }

    public function testValidateWithValidDataUsingTimestamp()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithValidDataUsingYYYYMMDDFormat()
    {
        $content = 'hmac-md5.example.com. 20210101000000 20211231235959 2 0 abcdef0123456789';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithInvalidAlgorithmName()
    {
        $content = '-invalid.algorithm. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('algorithm name', $result->getFirstError());
    }

    public function testValidateWithInvalidInceptionTime()
    {
        $content = 'hmac-sha256.example.com. invalid 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('inception time', $result->getFirstError());
    }

    public function testValidateWithInvalidExpirationTime()
    {
        $content = 'hmac-sha256.example.com. 1609459200 invalid 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('expiration time', $result->getFirstError());
    }

    public function testValidateWithInvalidMode()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 6 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('mode must be', $result->getFirstError());
    }

    public function testValidateWithInvalidError()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 24 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('error must be', $result->getFirstError());
    }

    public function testValidateWithInvalidKeyData()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 !@#$%^';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('key data', $result->getFirstError());
    }

    public function testValidateWithInvalidFormat()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3';  // Missing error and key-data
        $name = 'key.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // We don't check the exact error message as it comes from the hostname validator
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // We don't check the exact error message as it comes from the TTL validator
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithInvalidPriority()
    {
        $content = 'hmac-sha256.example.com. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==';
        $name = 'key.example.com';
        $prio = 10;  // Non-zero priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field', $result->getFirstError());
    }
}
