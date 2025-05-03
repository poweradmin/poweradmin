<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\NAPTRRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Tests for the NAPTRRecordValidator
 */
class NAPTRRecordValidatorTest extends TestCase
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
        $content = '100 10 "S" "SIP+D2U" "!^.*$!sip:info@example.com!" .';  // Valid NAPTR record
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
        $this->assertEquals($name, $result['name']);
        $this->assertEquals(0, $result['prio']); // Priority is in content
        $this->assertEquals(3600, $result['ttl']);
    }

    public function testValidateWithEmptyFlagsAndReplacement()
    {
        $content = '100 10 "" "SIP+D2U" "!^.*$!sip:info@example.com!" example.com';  // Valid with empty flags
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals($content, $result['content']);
    }

    public function testValidateWithInvalidOrder()
    {
        $content = '65536 10 "S" "SIP+D2U" "!^.*$!sip:info@example.com!" .';  // Order > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidPreference()
    {
        $content = '100 65536 "S" "SIP+D2U" "!^.*$!sip:info@example.com!" .';  // Preference > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidFlags()
    {
        $content = '100 10 "X" "SIP+D2U" "!^.*$!sip:info@example.com!" .';  // Invalid flag 'X'
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithUnquotedFlags()
    {
        $content = '100 10 S "SIP+D2U" "!^.*$!sip:info@example.com!" .';  // Unquoted flags
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithUnquotedService()
    {
        $content = '100 10 "S" SIP+D2U "!^.*$!sip:info@example.com!" .';  // Unquoted service
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithUnquotedRegexp()
    {
        $content = '100 10 "S" "SIP+D2U" !^.*$!sip:info@example.com! .';  // Unquoted regexp
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidReplacement()
    {
        $content = '100 10 "S" "SIP+D2U" "!^.*$!sip:info@example.com!" -invalid-domain.com';  // Invalid replacement
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithMissingFields()
    {
        $content = '100 10 "S" "SIP+D2U" "!^.*$!sip:info@example.com!"';  // Missing replacement
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '100 10 "S" "SIP+D2U" "!^.*$!sip:info@example.com!" .';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '100 10 "S" "SIP+D2U" "!^.*$!sip:info@example.com!" .';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '100 10 "S" "SIP+D2U" "!^.*$!sip:info@example.com!" .';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertIsArray($result);
        $this->assertEquals(86400, $result['ttl']);
    }
}
