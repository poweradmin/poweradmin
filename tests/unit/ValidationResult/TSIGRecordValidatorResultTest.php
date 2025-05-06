<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\TSIGRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for TSIGRecordValidator using ValidationResult pattern
 */
class TSIGRecordValidatorResultTest extends TestCase
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
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 0';
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidName()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 0';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidContentCharacters()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbu$TVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 0'; // Invalid characters
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG MAC must be a valid base64-encoded string', $result->getFirstError());
    }

    public function testValidateWithMissingComponents()
    {
        $content = 'hmac-sha256. 1620000000 300'; // Missing MAC, original-id, error, and other-len
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG record must contain at least algorithm-name, timestamp, fudge, mac, original-id, error, and other-len', $result->getFirstError());
    }

    public function testValidateWithInvalidAlgorithmName()
    {
        $content = 'hmac-sha256 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 0'; // Missing trailing dot
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG algorithm name must be a valid domain name ending with a dot', $result->getFirstError());
    }

    public function testValidateWithInvalidTimestamp()
    {
        $content = 'hmac-sha256. -1 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 0'; // Negative timestamp
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG timestamp must be a non-negative integer', $result->getFirstError());
    }

    public function testValidateWithNonNumericTimestamp()
    {
        $content = 'hmac-sha256. abc 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 0'; // Non-numeric timestamp
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG timestamp must be a non-negative integer', $result->getFirstError());
    }

    public function testValidateWithInvalidFudge()
    {
        $content = 'hmac-sha256. 1620000000 -300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 0'; // Negative fudge
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG fudge must be a non-negative integer', $result->getFirstError());
    }

    public function testValidateWithInvalidMAC()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4=$$ 12345 0 0'; // Invalid MAC
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG MAC must be a valid base64-encoded string or hexadecimal string', $result->getFirstError());
    }

    public function testValidateWithInvalidOriginalId()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 65536 0 0'; // Invalid original ID (too large)
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG original ID must be a number between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithInvalidError()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 24 0'; // Invalid error code (too large)
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG error must be a valid DNS RCODE number between 0 and 23', $result->getFirstError());
    }

    public function testValidateWithInvalidOtherLen()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 -1'; // Negative other-len
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG other-len must be a non-negative integer', $result->getFirstError());
    }

    public function testValidateWithInvalidOtherData()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 10 %invalid$data'; // Invalid other-data
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TSIG other-data must be a valid base64-encoded string', $result->getFirstError());
    }

    public function testValidateWithOtherDataHex()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 10 1a2b3c4d5e'; // Hex other-data
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithOtherDataBase64()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 10 YWJjZGVmZ2hpag=='; // Base64 other-data
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 0';
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 0';
        $name = 'tsig.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithNonzeroUnusedPriority()
    {
        $content = 'hmac-sha256. 1620000000 300 kp4/24gyYsEzbuTVJRUMoqGFmN3LYgVDEZr0Bkf0Pc4= 12345 0 0';
        $name = 'tsig.example.com';
        $prio = 10; // Non-zero priority, but TSIG ignores this
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        // Priority should be set to 0 regardless of input
        $this->assertEquals(0, $data['prio']);
    }
}
