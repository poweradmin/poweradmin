<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SPFRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

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

        $this->assertIsArray($result);
        // Content should be automatically quoted for SPF records
        $this->assertEquals('"v=spf1 ip4:192.168.0.0/24 include:example.net -all"', $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']); // SPF always uses 0
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithQuotedData()
    {
        $content = '"v=spf1 ip4:192.168.0.0/24 include:example.net -all"';
        $name = 'example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']); // Already has quotes, should remain the same
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']);
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'v=spf1 ip4:192.168.0.0/24 include:example.net -all';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidSPFVersion()
    {
        $content = 'v=spf2 ip4:192.168.0.0/24 include:example.net -all'; // Invalid SPF version (spf2)
        $name = 'example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidMechanism()
    {
        $content = 'v=spf1 badmechanism:example.net -all'; // Invalid mechanism
        $name = 'example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'v=spf1 ip4:192.168.0.0/24 include:example.net -all';
        $name = 'example.com';
        $prio = '';
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'v=spf1 ip4:192.168.0.0/24 include:example.net -all';
        $name = 'example.com';
        $prio = '';
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }

    public function testValidateWithNonZeroPriority()
    {
        $content = 'v=spf1 ip4:192.168.0.0/24 include:example.net -all';
        $name = 'example.com';
        $prio = 10; // Non-zero priority (should be ignored for SPF records)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['prio']); // Priority should always be 0 for SPF
    }

    public function testValidateWithComplexSPF()
    {
        $content = 'v=spf1 ip4:192.168.0.0/24 ip6:2001:db8::/32 include:_spf.example.net a:mail.example.org mx:mx.example.com ~all';
        $name = 'example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals('"' . $content . '"', $result['content']);
    }
}
