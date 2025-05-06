<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\KXRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for KXRecordValidator using ValidationResult pattern
 */
class KXRecordValidatorResultTest extends TestCase
{
    private KXRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new KXRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
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

    public function testValidateWithInvalidKeyExchangerHostname()
    {
        $content = '-invalid-hostname.example.com'; // Invalid key exchanger hostname
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid key exchanger hostname', $result->getFirstError());
    }

    public function testValidateWithInvalidDomainName()
    {
        $content = 'mail.example.com';
        $name = '-invalid-domain.example.com'; // Invalid domain name
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 'invalid'; // Non-numeric priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid value for KX priority field', $result->getFirstError());
    }

    public function testValidateWithPriorityOutOfRange()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 70000; // Out of range (>65535)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid value for KX priority field', $result->getFirstError());
    }

    public function testValidateWithNegativePriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = -1; // Negative priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithEmptyPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = ''; // Empty priority should default to 10
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(10, $data['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithLowPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 0; // Valid lowest priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(0, $data['prio']);
    }
}
