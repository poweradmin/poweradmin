<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\CAARecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for CAARecordValidator using ValidationResult pattern
 */
class CAARecordValidatorResultTest extends TestCase
{
    private CAARecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new CAARecordValidator($this->configMock);
    }

    public function testValidateWithValidIssueData()
    {
        $content = '0 issue "ca.example.com"';
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

    public function testValidateWithValidIssuewildData()
    {
        $content = '0 issuewild "ca.example.com"';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithValidIodefData()
    {
        $content = '0 iodef "mailto:security@example.com"';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithMissingField()
    {
        $content = '0 issue'; // Missing value
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('flags, tag, and value', $result->getFirstError());
    }

    public function testValidateWithInvalidFlag()
    {
        $content = '256 issue "ca.example.com"'; // Flag > 255
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('flags', $result->getFirstError());
    }

    public function testValidateWithNegativeFlag()
    {
        $content = '-1 issue "ca.example.com"'; // Negative flag
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('flags', $result->getFirstError());
    }

    public function testValidateWithInvalidTag()
    {
        $content = '0 invalid "ca.example.com"'; // Invalid tag
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('tag', $result->getFirstError());
    }

    public function testValidateWithMissingQuotes()
    {
        $content = '0 issue ca.example.com'; // Missing quotes
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('quotes', $result->getFirstError());
    }

    public function testValidateWithInvalidQuoting()
    {
        $content = '0 issue "ca.example.com with "quotes"'; // Improperly quoted value
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('quotes', $result->getFirstError());
    }

    public function testValidateWithInvalidIodefUrl()
    {
        $content = '0 iodef "ftp://security.example.com"'; // Invalid URL protocol for iodef
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('URL', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '0 issue "ca.example.com"';
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
        $content = '0 issue "ca.example.com"';
        $name = 'example.com';
        $prio = 10; // CAA records must use priority 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Priority field', $result->getFirstError());
    }

    public function testValidateWithEmptyPriority()
    {
        $content = '0 issue "ca.example.com"';
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
        $content = '0 issue "ca.example.com"';
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
