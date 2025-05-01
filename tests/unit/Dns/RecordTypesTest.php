<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Domain\Service\DnsValidation\CSYNCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DSRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\LOCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SPFRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for various record type validation
 */
class RecordTypesTest extends BaseDnsTest
{
    private CSYNCRecordValidator $csyncValidator;
    private DSRecordValidator $dsValidator;

    protected function setUp(): void
    {
        parent::setUp();
        $configMock = $this->createMock(ConfigurationManager::class);
        $this->csyncValidator = new CSYNCRecordValidator($configMock);
        $this->dsValidator = new DSRecordValidator($configMock);
    }

    public function testIsValidSPF()
    {
        $configMock = $this->createMock(ConfigurationManager::class);
        $validator = new SPFRecordValidator($configMock);

        // Valid SPF records
        $this->assertTrue($validator->validate('v=spf1 include:example.com ~all', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('v=spf1 ip4:192.168.0.1/24 -all', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('v=spf1 a mx -all', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('v=spf1 a:example.com mx:mail.example.com -all', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('v=spf1 ip6:2001:db8::/32 ~all', 'example.com', 0, 3600, 3600) !== false);

        // Invalid SPF records
        $this->assertFalse($validator->validate('v=spf2 include:example.com ~all', 'example.com', 0, 3600, 3600)); // Wrong version
        $this->assertFalse($validator->validate('include:example.com ~all', 'example.com', 0, 3600, 3600)); // Missing version
        $this->assertFalse($validator->validate('v=spf1 invalid:example.com ~all', 'example.com', 0, 3600, 3600)); // Invalid mechanism
        $this->assertFalse($validator->validate('v=spf1 ip4:999.168.0.1/24 -all', 'example.com', 0, 3600, 3600)); // Invalid IP
    }

    public function testIsValidDS()
    {
        // Valid DS records
        $this->assertTrue($this->dsValidator->isValidDSContent('45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0'));
        $this->assertTrue($this->dsValidator->isValidDSContent('15288 5 2 CE0EB9E59EE1DE2C681A330E3A7C08376F28602CDF990EE4EC88D2A8BDB51539'));

        // Invalid DS records
        $this->assertFalse($this->dsValidator->isValidDSContent('45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0;'));
        $this->assertFalse($this->dsValidator->isValidDSContent('2371 13 2 1F987CC6583E92DF0890718C42')); // Too short digest
        $this->assertFalse($this->dsValidator->isValidDSContent('2371 13 2 1F987CC6583E92DF0890718C42 ; ( SHA1 digest )'));
        $this->assertFalse($this->dsValidator->isValidDSContent('invalid'));
    }

    public function testIsValidLocation()
    {
        $configMock = $this->createMock(ConfigurationManager::class);

        $validator = new LOCRecordValidator($configMock);
        // Valid LOC records
        $this->assertTrue($validator->validate('37 23 30.900 N 121 59 19.000 W 7.00m 100.00m 100.00m 2.00m', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('42 21 54 N 71 06 18 W -24m 30m', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('42 21 43.952 N 71 5 6.344 W -24m 1m 200m', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('52 14 05 N 00 08 50 E 10m', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('32 7 19 S 116 2 25 E 10m', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('42 21 28.764 N 71 00 51.617 W -44m 2000m', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('90 59 59.9 N 10 18 E 42849671.91m 1m', 'example.com', 0, 3600, 3600) !== false);
        $this->assertTrue($validator->validate('9 10 S 12 22 33.4 E -100000.00m 2m 34 3m', 'example.com', 0, 3600, 3600) !== false);

        // Invalid LOC records
        // hp precision too high
        $this->assertFalse($validator->validate('37 23 30.900 N 121 59 19.000 W 7.00m 100.00m 100.050m 2.00m', 'example.com', 0, 3600, 3600));

        // S is no long.
        $this->assertFalse($validator->validate('42 21 54 N 71 06 18 S -24m 30m', 'example.com', 0, 3600, 3600));

        // s2 precision too high
        $this->assertFalse($validator->validate('42 21 43.952 N 71 5 6.4344 W -24m 1m 200m', 'example.com', 0, 3600, 3600));

        // s2 maxes to 59.99
        $this->assertFalse($validator->validate('52 14 05 N 00 08 60 E 10m', 'example.com', 0, 3600, 3600));

        // long. maxes to 180
        $this->assertFalse($validator->validate('32 7 19 S 186 2 25 E 10m', 'example.com', 0, 3600, 3600));

        // alt maxes to 42849672.95
        $this->assertFalse($validator->validate('90 59 59.9 N 10 18 E 42849672.96m 1m', 'example.com', 0, 3600, 3600));

        // alt maxes to -100000.00
        $this->assertFalse($validator->validate('9 10 S 12 22 33.4 E -110000.00m 2m 34 3m', 'example.com', 0, 3600, 3600));
    }

    public function testIsValidCsyncWithValidInput()
    {
        // Valid CSYNC record with both flags set and multiple record types
        $this->assertTrue($this->csyncValidator->isValidCSYNCContent("1234 3 A NS AAAA"));

        // Valid CSYNC record with immediate flag set (1)
        $this->assertTrue($this->csyncValidator->isValidCSYNCContent("4294967295 1 NS"));

        // Valid CSYNC record with soaminimum flag set (2)
        $this->assertTrue($this->csyncValidator->isValidCSYNCContent("0 2 A"));

        // Valid CSYNC record with no flags set (0)
        $this->assertTrue($this->csyncValidator->isValidCSYNCContent("42 0 CNAME"));
    }

    public function testIsValidCsyncWithInvalidSoaSerial()
    {
        // SOA Serial is not a number
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("abc 3 A NS"));

        // SOA Serial is negative
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("-1 3 A NS"));

        // SOA Serial exceeds 32-bit unsigned integer maximum (4294967295)
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("4294967296 3 A NS"));

        // Missing SOA Serial
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("3 A NS"));
    }

    public function testIsValidCsyncWithInvalidFlags()
    {
        // Flag is not a number
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("1234 abc A NS"));

        // Flag is negative
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("1234 -1 A NS"));

        // Flag exceeds maximum allowed value (3)
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("1234 4 A NS"));

        // Missing flag
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("1234"));
    }

    public function testIsValidCsyncWithInvalidTypes()
    {
        // Invalid record type
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("1234 3 INVALID_TYPE"));

        // No record types specified
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("1234 3"));

        // Mixed valid and invalid record types
        $this->assertFalse($this->csyncValidator->isValidCSYNCContent("1234 3 A INVALID_TYPE NS"));
    }

    public function testIsValidRrHinfoContent()
    {
        // Valid HINFO content formats
        $this->assertTrue(Dns::is_valid_rr_hinfo_content('PC Intel'));
        $this->assertTrue(Dns::is_valid_rr_hinfo_content('"PC with spaces" Linux'));
        $this->assertTrue(Dns::is_valid_rr_hinfo_content('"Windows Server" "Ubuntu Linux"'));
        $this->assertTrue(Dns::is_valid_rr_hinfo_content('Intel-PC FreeBSD'));
    }
}
