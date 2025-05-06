<?php

namespace unit\ValidationResult;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SPFRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for SPFRecordValidator using ValidationResult pattern
 */
class SPFRecordValidatorResultTest extends TestCase
{
    private SPFRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new SPFRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'v=spf1 ip4:192.0.2.0/24 ip4:198.51.100.123 a -all';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        // Should be auto-quoted in the validator
        $this->assertEquals('"' . $content . '"', $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithPreQuotedData()
    {
        $content = '"v=spf1 ip4:192.0.2.0/24 ip4:198.51.100.123 a -all"';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithMissingVersion()
    {
        $content = 'ip4:192.0.2.0/24 ip4:198.51.100.123 a -all'; // Missing v=spf1
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('must start with "v=spf1"', $result->getFirstError());
    }

    public function testValidateWithInvalidSPFFormat()
    {
        $content = 'v=spf1 unknown:directive ~all'; // Invalid mechanism
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('format is invalid', $result->getFirstError());
    }

    public function testValidateWithInvalidIPv4Directive()
    {
        $content = 'v=spf1 ip4:256.0.0.1 -all'; // Invalid IPv4 address
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidMechanismQualifier()
    {
        $content = 'v=spf1 *ip4:192.0.2.1 -all'; // Invalid qualifier
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'v=spf1 ip4:192.0.2.0/24 -all';
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
        $content = 'v=spf1 ip4:192.0.2.0/24 -all';
        $name = 'example.com';
        $prio = 10; // SPF records must use priority 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Priority field', $result->getFirstError());
    }

    public function testValidateWithEmptyPriority()
    {
        $content = 'v=spf1 ip4:192.0.2.0/24 -all';
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
        $content = 'v=spf1 ip4:192.0.2.0/24 -all';
        $name = 'example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithAllMechanisms()
    {
        $content = 'v=spf1 a mx include:example.net ip4:192.0.2.0/24 ip6:2001:db8::/32 exists:%{i}.domain.com ?all';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }
}
