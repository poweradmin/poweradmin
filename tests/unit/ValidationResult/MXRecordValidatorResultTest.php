<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\MXRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for MXRecordValidator using ValidationResult pattern
 */
class MXRecordValidatorResultTest extends TestCase
{
    private MXRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new MXRecordValidator($this->configMock);
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

    public function testValidateWithInvalidMailServerHostname()
    {
        $content = '-invalid-hostname.example.com'; // Invalid mail server hostname
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid mail server hostname', $result->getFirstError());
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
        $prio = 65536; // Invalid priority (above 65535)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid value for MX priority', $result->getFirstError());
    }

    public function testValidateWithNegativePriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = -1; // Invalid priority (negative)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid value for MX priority', $result->getFirstError());
    }

    public function testValidateWithNonNumericPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 'abc'; // Invalid priority (non-numeric)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid value for MX priority', $result->getFirstError());
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
        $this->assertEquals(10, $data['prio']); // Default MX priority is 10
    }

    public function testValidateWithLowPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 0; // Valid lowest priority according to RFC
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithHighPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 65535; // Valid highest priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(65535, $data['prio']);
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

    public function testValidateWithStringTTL()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = '3600'; // String TTL should be parsed correctly
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithStringPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = '20'; // String priority should be parsed correctly
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(20, $data['prio']);
        $this->assertIsInt($data['prio']);
    }
}
