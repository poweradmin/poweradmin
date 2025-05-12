<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SPFRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;

/**
 * Tests for the SPFRecordValidator
 */
class SPFRecordValidatorTest extends TestCase
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
        $content = 'v=spf1 ip4:192.168.0.0/24 include:example.net -all';
        $name = 'example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        // Content should be automatically quoted for SPF records
        $data = $result->getData();
        $this->assertEquals('"v=spf1 ip4:192.168.0.0/24 include:example.net -all"', $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']); // SPF always uses 0
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithQuotedData()
    {
        $content = '"v=spf1 ip4:192.168.0.0/24 include:example.net -all"';
        $name = 'example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']); // Already has quotes, should remain the same
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'v=spf1 ip4:192.168.0.0/24 include:example.net -all';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidSPFVersion()
    {
        $content = 'v=spf2 ip4:192.168.0.0/24 include:example.net -all'; // Invalid SPF version (spf2)
        $name = 'example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('SPF record must start with', $result->getFirstError());
    }

    public function testValidateWithInvalidMechanism()
    {
        // Since the implementation is strict about RFC compliance,
        // we should expect validation to fail with an invalid mechanism
        $content = 'v=spf1 badmechanism:example.net -all'; // Invalid mechanism
        $name = 'example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        // The implementation treats unknown mechanisms as errors
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Unknown mechanism', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'v=spf1 ip4:192.168.0.0/24 include:example.net -all';
        $name = 'example.com';
        $prio = '';
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'v=spf1 ip4:192.168.0.0/24 include:example.net -all';
        $name = 'example.com';
        $prio = '';
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithNonZeroPriority()
    {
        $content = 'v=spf1 ip4:192.168.0.0/24 include:example.net -all';
        $name = 'example.com';
        $prio = 10; // Non-zero priority (invalid for SPF records)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field for SPF records must be 0 or empty', $result->getFirstError());
    }

    public function testValidateWithComplexSPF()
    {
        $content = 'v=spf1 ip4:192.168.0.0/24 ip6:2001:db8::/32 include:_spf.example.net a:mail.example.org mx:mx.example.com ~all';
        $name = 'example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('"' . $content . '"', $data['content']);
    }

    /**
     * Test validatePriority method
     */
    public function testValidatePriority()
    {
        $reflection = new ReflectionClass(SPFRecordValidator::class);
        $method = $reflection->getMethod('validatePriority');
        $method->setAccessible(true);

        // Test with empty priority (should return 0)
        $result = $method->invoke($this->validator, '');
        $this->assertTrue($result->isValid());
        $this->assertEquals(0, $result->getData());

        // Test with null priority (should return 0)
        $result = $method->invoke($this->validator, null);
        $this->assertTrue($result->isValid());
        $this->assertEquals(0, $result->getData());

        // Test with 0 priority (should be valid)
        $result = $method->invoke($this->validator, 0);
        $this->assertTrue($result->isValid());
        $this->assertEquals(0, $result->getData());

        // Test with non-zero priority (should be invalid)
        $result = $method->invoke($this->validator, 10);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field for SPF records must be 0 or empty', $result->getFirstError());
    }

    /**
     * Test basic SPF validation
     */
    public function testValidateSPFContent()
    {
        // We'll use a simplified test that only verifies the v=spf1 prefix check
        // This isolates the test from internal implementation details
        $content1 = 'v=spf1 ip4:192.168.0.0/24 include:example.net -all';
        $content2 = 'v=spf2 ip4:192.168.0.0/24 include:example.net -all';

        $reflection = new ReflectionClass(SPFRecordValidator::class);
        $method = $reflection->getMethod('validateSPFContent');
        $method->setAccessible(true);

        // Valid SPF version should pass
        $result1 = $method->invoke($this->validator, $content1);
        $this->assertTrue($result1->isValid(), "Valid SPF content should validate");

        // Invalid SPF version should fail
        $result2 = $method->invoke($this->validator, $content2);
        $this->assertFalse($result2->isValid(), "Invalid SPF version should fail validation");
        $this->assertStringContainsString('SPF record must start with', $result2->getFirstError());
    }
}
