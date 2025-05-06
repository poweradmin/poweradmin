<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\NAPTRRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for NAPTRRecordValidator using ValidationResult pattern
 */
class NAPTRRecordValidatorResultTest extends TestCase
{
    private NAPTRRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new NAPTRRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .';
        $name = 'test.example.com';
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

    public function testValidateWithInvalidOrderValue()
    {
        $content = '65536 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Invalid order (out of range)
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('order must be a number between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithInvalidPreferenceValue()
    {
        $content = '100 -1 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Invalid preference (negative)
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('preference must be a number between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithInvalidFlagsFormat()
    {
        $content = '100 10 u "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Flags not quoted
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('flags must be a quoted string', $result->getFirstError());
    }

    public function testValidateWithInvalidFlagsValue()
    {
        $content = '100 10 "X" "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Invalid flag 'X'
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('flags must contain only A, P, S, or U', $result->getFirstError());
    }

    public function testValidateWithInvalidServiceFormat()
    {
        $content = '100 10 "u" sip+E2U "!^.*$!sip:info@example.com!" .'; // Service not quoted
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('service must be a quoted string', $result->getFirstError());
    }

    public function testValidateWithInvalidRegexpFormat()
    {
        $content = '100 10 "u" "sip+E2U" !^.*$!sip:info@example.com! .'; // Regexp not quoted
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('regexp must be a quoted string', $result->getFirstError());
    }

    public function testValidateWithInvalidReplacement()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" -invalid.example.com.'; // Invalid domain name
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('replacement must be either "." or a valid fully-qualified domain name', $result->getFirstError());
    }

    public function testValidateWithInvalidName()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .';
        $name = 'test.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithMissingComponents()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!"'; // Missing replacement
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('must contain order, preference, flags, service, regexp, and replacement', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .';
        $name = 'test.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithEmptyFlags()
    {
        $content = '100 10 "" "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Empty flags is valid
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithMultipleFlags()
    {
        $content = '100 10 "SU" "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Multiple valid flags
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }
}
