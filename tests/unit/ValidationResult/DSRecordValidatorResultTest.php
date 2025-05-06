<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DSRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for DSRecordValidator using ValidationResult pattern
 */
class DSRecordValidatorResultTest extends TestCase
{
    private DSRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new DSRecordValidator($this->configMock);
    }

    public function testValidateWithValidSHA1Data()
    {
        // valid DS record with SHA-1 digest (digest type 1)
        $content = '12345 8 1 0123456789abcdef0123456789abcdef01234567';
        $name = 'example.com';
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

    public function testValidateWithValidSHA256Data()
    {
        // valid DS record with SHA-256 digest (digest type 2)
        $content = '12345 8 2 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $name = 'example.com';
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

    public function testValidateWithValidSHA384Data()
    {
        // valid DS record with SHA-384 digest (digest type 4)
        $digest = str_repeat('0123456789abcdef', 6); // 96 hex chars
        $content = "12345 8 4 $digest";
        $name = 'example.com';
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

    public function testValidateWithInvalidFormat()
    {
        $content = '12345 8 1'; // Missing digest
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('format', $result->getFirstError());
    }

    public function testValidateWithInvalidKeyTag()
    {
        $content = '0 8 1 0123456789abcdef0123456789abcdef01234567'; // Invalid key tag (< 1)
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Key tag', $result->getFirstError());
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '12345 99 1 0123456789abcdef0123456789abcdef01234567'; // Invalid algorithm
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Algorithm', $result->getFirstError());
    }

    public function testValidateWithInvalidDigestType()
    {
        $content = '12345 8 3 0123456789abcdef0123456789abcdef01234567'; // Invalid digest type (3)
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Digest type', $result->getFirstError());
    }

    public function testValidateWithIncorrectDigestLength()
    {
        // SHA-1 should be 40 characters but providing only 38
        $content = '12345 8 1 0123456789abcdef0123456789abcdef0123';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('digest must be exactly 40', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '12345 8 1 0123456789abcdef0123456789abcdef01234567';
        $name = '-invalid.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithNonZeroPriority()
    {
        $content = '12345 8 1 0123456789abcdef0123456789abcdef01234567';
        $name = 'example.com';
        $prio = 10; // DS records should use priority 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Priority field', $result->getFirstError());
    }

    public function testValidateWithEmptyPriority()
    {
        $content = '12345 8 1 0123456789abcdef0123456789abcdef01234567';
        $name = 'example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '12345 8 1 0123456789abcdef0123456789abcdef01234567';
        $name = 'example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
