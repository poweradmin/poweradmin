<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\APLRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for APLRecordValidator using ValidationResult pattern
 */
class APLRecordValidatorResultTest extends TestCase
{
    private APLRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new APLRecordValidator($this->configMock);
    }

    public function testValidateWithValidIPv4Data()
    {
        $content = '1:192.168.0.0/24';
        $name = 'apl.example.com';
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

    public function testValidateWithValidIPv6Data()
    {
        $content = '2:2001:db8::/32';
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithMultipleElements()
    {
        $content = '1:192.168.0.0/24 2:2001:db8::/32';
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithNegationElement()
    {
        $content = '1:192.168.0.0/24 !2:2001:db8::/32';
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithEmptyContent()
    {
        $content = '';
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('APL record content cannot be empty', $result->getFirstError());
    }

    public function testValidateWithInvalidFormat()
    {
        $content = 'invalid-format';
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid APL element format', $result->getFirstError());
    }

    public function testValidateWithInvalidAFI()
    {
        $content = '3:192.168.0.0/24'; // Invalid AFI (3)
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid Address Family Identifier', $result->getFirstError());
    }

    public function testValidateWithInvalidIPv4()
    {
        $content = '1:192.168.300.1/24'; // Invalid IPv4
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid IPv4 address', $result->getFirstError());
    }

    public function testValidateWithInvalidIPv6()
    {
        $content = '2:2001:zz8::/32'; // Invalid IPv6
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid IPv6 address', $result->getFirstError());
    }

    public function testValidateWithInvalidIPv4Prefix()
    {
        $content = '1:192.168.0.0/40'; // Invalid prefix (>32)
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('IPv4 prefix must be between 0 and 32', $result->getFirstError());
    }

    public function testValidateWithInvalidIPv6Prefix()
    {
        $content = '2:2001:db8::/200'; // Invalid prefix (>128)
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('IPv6 prefix must be between 0 and 128', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '1:192.168.0.0/24';
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
        $content = '1:192.168.0.0/24';
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '1:192.168.0.0/24';
        $name = 'apl.example.com';
        $prio = 10; // Invalid priority for APL record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    public function testValidateWithEmptyPriority()
    {
        $content = '1:192.168.0.0/24';
        $name = 'apl.example.com';
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
        $content = '1:192.168.0.0/24';
        $name = 'apl.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
