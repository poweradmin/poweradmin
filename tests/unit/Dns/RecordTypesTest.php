<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsValidation\CSYNCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DSRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HINFORecordValidator;
use Poweradmin\Domain\Service\DnsValidation\LOCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SPFRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
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

    public function testValidateSPF()
    {
        $configMock = $this->createMock(ConfigurationManager::class);
        $validator = new SPFRecordValidator($configMock);

        // Valid SPF records
        $result1 = $validator->validate('v=spf1 include:example.com ~all', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result1->isValid());

        $result2 = $validator->validate('v=spf1 ip4:192.168.0.1/24 -all', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result2->isValid());

        $result3 = $validator->validate('v=spf1 a mx -all', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result3->isValid());

        $result4 = $validator->validate('v=spf1 a:example.com mx:mail.example.com -all', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result4->isValid());

        $result5 = $validator->validate('v=spf1 ip6:2001:db8::/32 ~all', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result5->isValid());

        // Invalid SPF records
        $result6 = $validator->validate('v=spf2 include:example.com ~all', 'example.com', 0, 3600, 3600); // Wrong version
        $this->assertFalse($result6->isValid());

        $result7 = $validator->validate('include:example.com ~all', 'example.com', 0, 3600, 3600); // Missing version
        $this->assertFalse($result7->isValid());

        // This test validates that an SPF record with an invalid mechanism is rejected
        $result8 = $validator->validate('v=spf1 invalid:example.com ~all', 'example.com', 0, 3600, 3600); // Invalid mechanism
        $this->assertFalse($result8->isValid());
        $this->assertStringContainsString('Unknown mechanism', $result8->getFirstError());

        // This test validates that an SPF record with an invalid IP is rejected
        $result9 = $validator->validate('v=spf1 ip4:999.168.0.1/24 -all', 'example.com', 0, 3600, 3600); // Invalid IP
        $this->assertFalse($result9->isValid());
        $this->assertStringContainsString('Invalid IPv4', $result9->getFirstError());
    }

    public function testValidateDSRecordContent()
    {
        // Valid DS records
        $result1 = $this->dsValidator->validateDSRecordContent('45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0');
        $this->assertTrue($result1->isValid());

        $result2 = $this->dsValidator->validateDSRecordContent('15288 5 2 CE0EB9E59EE1DE2C681A330E3A7C08376F28602CDF990EE4EC88D2A8BDB51539');
        $this->assertTrue($result2->isValid());

        // Invalid DS records
        $result3 = $this->dsValidator->validateDSRecordContent('45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0;');
        $this->assertFalse($result3->isValid());

        $result4 = $this->dsValidator->validateDSRecordContent('2371 13 2 1F987CC6583E92DF0890718C42'); // Too short digest
        $this->assertFalse($result4->isValid());

        $result5 = $this->dsValidator->validateDSRecordContent('2371 13 2 1F987CC6583E92DF0890718C42 ; ( SHA1 digest )');
        $this->assertFalse($result5->isValid());

        $result6 = $this->dsValidator->validateDSRecordContent('invalid');
        $this->assertFalse($result6->isValid());
    }

    public function testValidateLocation()
    {
        $configMock = $this->createMock(ConfigurationManager::class);

        $validator = new LOCRecordValidator($configMock);
        // Valid LOC records
        $result1 = $validator->validate('37 23 30.900 N 121 59 19.000 W 7.00m 100.00m 100.00m 2.00m', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result1->isValid());

        $result2 = $validator->validate('42 21 54 N 71 06 18 W -24m 30m', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result2->isValid());

        $result3 = $validator->validate('42 21 43.952 N 71 5 6.344 W -24m 1m 200m', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result3->isValid());

        $result4 = $validator->validate('52 14 05 N 00 08 50 E 10m', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result4->isValid());

        $result5 = $validator->validate('32 7 19 S 116 2 25 E 10m', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result5->isValid());

        $result6 = $validator->validate('42 21 28.764 N 71 00 51.617 W -44m 2000m', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result6->isValid());

        $result7 = $validator->validate('90 59 59.9 N 10 18 E 42849671.91m 1m', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result7->isValid());

        $result8 = $validator->validate('9 10 S 12 22 33.4 E -100000.00m 2m 34 3m', 'example.com', 0, 3600, 3600);
        $this->assertTrue($result8->isValid());

        // Invalid LOC records
        // hp precision too high
        $result9 = $validator->validate('37 23 30.900 N 121 59 19.000 W 7.00m 100.00m 100.050m 2.00m', 'example.com', 0, 3600, 3600);
        $this->assertFalse($result9->isValid());

        // S is no long.
        $result10 = $validator->validate('42 21 54 N 71 06 18 S -24m 30m', 'example.com', 0, 3600, 3600);
        $this->assertFalse($result10->isValid());

        // s2 precision too high
        $result11 = $validator->validate('42 21 43.952 N 71 5 6.4344 W -24m 1m 200m', 'example.com', 0, 3600, 3600);
        $this->assertFalse($result11->isValid());

        // s2 maxes to 59.99
        $result12 = $validator->validate('52 14 05 N 00 08 60 E 10m', 'example.com', 0, 3600, 3600);
        $this->assertFalse($result12->isValid());

        // long. maxes to 180
        $result13 = $validator->validate('32 7 19 S 186 2 25 E 10m', 'example.com', 0, 3600, 3600);
        $this->assertFalse($result13->isValid());

        // alt maxes to 42849672.95
        $result14 = $validator->validate('90 59 59.9 N 10 18 E 42849672.96m 1m', 'example.com', 0, 3600, 3600);
        $this->assertFalse($result14->isValid());

        // alt maxes to -100000.00
        $result15 = $validator->validate('9 10 S 12 22 33.4 E -110000.00m 2m 34 3m', 'example.com', 0, 3600, 3600);
        $this->assertFalse($result15->isValid());
    }

    public function testValidateCSYNCWithValidInput()
    {
        // Valid CSYNC record with both flags set and multiple record types
        $result1 = $this->csyncValidator->validateCSYNCRecordContent("1234 3 A NS AAAA");
        $this->assertTrue($result1->isValid());

        // Valid CSYNC record with immediate flag set (1)
        $result2 = $this->csyncValidator->validateCSYNCRecordContent("4294967295 1 NS");
        $this->assertTrue($result2->isValid());

        // Valid CSYNC record with soaminimum flag set (2)
        $result3 = $this->csyncValidator->validateCSYNCRecordContent("0 2 A");
        $this->assertTrue($result3->isValid());

        // Valid CSYNC record with no flags set (0)
        $result4 = $this->csyncValidator->validateCSYNCRecordContent("42 0 CNAME");
        $this->assertTrue($result4->isValid());
    }

    public function testValidateCSYNCWithInvalidSoaSerial()
    {
        // SOA Serial is not a number
        $result1 = $this->csyncValidator->validateCSYNCRecordContent("abc 3 A NS");
        $this->assertFalse($result1->isValid());

        // SOA Serial is negative
        $result2 = $this->csyncValidator->validateCSYNCRecordContent("-1 3 A NS");
        $this->assertFalse($result2->isValid());

        // SOA Serial exceeds 32-bit unsigned integer maximum (4294967295)
        $result3 = $this->csyncValidator->validateCSYNCRecordContent("4294967296 3 A NS");
        $this->assertFalse($result3->isValid());

        // Missing SOA Serial
        $result4 = $this->csyncValidator->validateCSYNCRecordContent("3 A NS");
        $this->assertFalse($result4->isValid());
    }

    public function testValidateCSYNCWithInvalidFlags()
    {
        // Flag is not a number
        $result1 = $this->csyncValidator->validateCSYNCRecordContent("1234 abc A NS");
        $this->assertFalse($result1->isValid());

        // Flag is negative
        $result2 = $this->csyncValidator->validateCSYNCRecordContent("1234 -1 A NS");
        $this->assertFalse($result2->isValid());

        // Flag exceeds maximum allowed value (3)
        $result3 = $this->csyncValidator->validateCSYNCRecordContent("1234 4 A NS");
        $this->assertFalse($result3->isValid());

        // Missing flag
        $result4 = $this->csyncValidator->validateCSYNCRecordContent("1234");
        $this->assertFalse($result4->isValid());
    }

    public function testValidateCSYNCWithInvalidTypes()
    {
        // Invalid record type
        $result1 = $this->csyncValidator->validateCSYNCRecordContent("1234 3 INVALID_TYPE");
        $this->assertFalse($result1->isValid());

        // No record types specified
        $result2 = $this->csyncValidator->validateCSYNCRecordContent("1234 3");
        $this->assertFalse($result2->isValid());

        // Mixed valid and invalid record types
        $result3 = $this->csyncValidator->validateCSYNCRecordContent("1234 3 A INVALID_TYPE NS");
        $this->assertFalse($result3->isValid());
    }

    public function testValidateHINFOContent()
    {
        $configMock = $this->createMock(ConfigurationManager::class);
        $validator = new HINFORecordValidator($configMock);

        // Valid HINFO content formats
        $result1 = $validator->validate('PC Intel', 'host.example.com', 0, 3600, 3600);
        $this->assertTrue($result1->isValid());

        $result2 = $validator->validate('"PC with spaces" Linux', 'host.example.com', 0, 3600, 3600);
        $this->assertTrue($result2->isValid());

        $result3 = $validator->validate('"Windows Server" "Ubuntu Linux"', 'host.example.com', 0, 3600, 3600);
        $this->assertTrue($result3->isValid());

        $result4 = $validator->validate('Intel-PC FreeBSD', 'host.example.com', 0, 3600, 3600);
        $this->assertTrue($result4->isValid());

        // Invalid HINFO content formats
        $result5 = $validator->validate('PC', 'host.example.com', 0, 3600, 3600); // Missing second field
        $this->assertFalse($result5->isValid());

        $result6 = $validator->validate('PC Linux Server', 'host.example.com', 0, 3600, 3600); // Too many fields
        $this->assertFalse($result6->isValid());

        $result7 = $validator->validate('"PC" "Linux" "Extra"', 'host.example.com', 0, 3600, 3600); // Too many fields
        $this->assertFalse($result7->isValid());

        $result8 = $validator->validate('', 'host.example.com', 0, 3600, 3600); // Empty content
        $this->assertFalse($result8->isValid());

        // Invalid hostname test
        $result9 = $validator->validate('PC Intel', 'invalid..hostname', 0, 3600, 3600);
        $this->assertFalse($result9->isValid());
    }

    public function testValidateSRV()
    {
        $configMock = $this->createMock(ConfigurationManager::class);
        $validator = new SRVRecordValidator($configMock);

        // Valid SRV records
        $result1 = $validator->validate('10 20 5060 sip.example.com', '_sip._tcp.example.com', 0, 3600, 3600);
        $this->assertTrue($result1->isValid());

        $result2 = $validator->validate('0 5 80 web.example.com', '_http._tcp.example.com', 0, 3600, 3600);
        $this->assertTrue($result2->isValid());

        $result3 = $validator->validate('30 0 443 secure.example.com', '_https._tcp.example.com', 0, 3600, 3600);
        $this->assertTrue($result3->isValid());

        $result4 = $validator->validate('1 10 9 server.example.com', '_submission._tcp.example.com', 0, 3600, 3600);
        $this->assertTrue($result4->isValid());

        // Invalid SRV records
        // Invalid name format
        $result5 = $validator->validate('0 5 80 web.example.com', 'invalid.example.com', 0, 3600, 3600);
        $this->assertFalse($result5->isValid());

        $result6 = $validator->validate('0 5 80 web.example.com', '_invalid_tcp.example.com', 0, 3600, 3600);
        $this->assertFalse($result6->isValid());

        // Invalid content format
        $result7 = $validator->validate('invalid 5 80 web.example.com', '_http._tcp.example.com', 0, 3600, 3600); // Invalid priority
        $this->assertFalse($result7->isValid());

        $result8 = $validator->validate('0 invalid 80 web.example.com', '_http._tcp.example.com', 0, 3600, 3600); // Invalid weight
        $this->assertFalse($result8->isValid());

        $result9 = $validator->validate('0 5 invalid web.example.com', '_http._tcp.example.com', 0, 3600, 3600); // Invalid port
        $this->assertFalse($result9->isValid());

        $result10 = $validator->validate('0 5 80 @invalid@', '_http._tcp.example.com', 0, 3600, 3600); // Invalid target
        $this->assertFalse($result10->isValid());

        // Out of range values
        $result11 = $validator->validate('65536 5 80 web.example.com', '_http._tcp.example.com', 0, 3600, 3600); // Priority too high
        $this->assertFalse($result11->isValid());

        $result12 = $validator->validate('0 65536 80 web.example.com', '_http._tcp.example.com', 0, 3600, 3600); // Weight too high
        $this->assertFalse($result12->isValid());

        $result13 = $validator->validate('0 5 65536 web.example.com', '_http._tcp.example.com', 0, 3600, 3600); // Port too high
        $this->assertFalse($result13->isValid());

        // Wrong number of fields
        $result14 = $validator->validate('0 5 web.example.com', '_http._tcp.example.com', 0, 3600, 3600); // Missing port
        $this->assertFalse($result14->isValid());

        $result15 = $validator->validate('0 5 80 web.example.com extra', '_http._tcp.example.com', 0, 3600, 3600); // Extra field
        $this->assertFalse($result15->isValid());
    }

    public function testValidateHINFO()
    {
        $configMock = $this->createMock(ConfigurationManager::class);
        $validator = new HINFORecordValidator($configMock);

        // Valid HINFO records
        $result1 = $validator->validate('PC Intel', 'host.example.com', 0, 3600, 3600);
        $this->assertTrue($result1->isValid());

        $result2 = $validator->validate('"PC with spaces" Linux', 'host.example.com', 0, 3600, 3600);
        $this->assertTrue($result2->isValid());

        $result3 = $validator->validate('"Windows Server" "Ubuntu Linux"', 'host.example.com', 0, 3600, 3600);
        $this->assertTrue($result3->isValid());

        $result4 = $validator->validate('Intel-PC FreeBSD', 'host.example.com', 0, 3600, 3600);
        $this->assertTrue($result4->isValid());

        // Invalid HINFO records
        // Missing second field
        $result5 = $validator->validate('PC', 'host.example.com', 0, 3600, 3600);
        $this->assertFalse($result5->isValid());

        // Empty fields
        $result6 = $validator->validate('" " Linux', 'host.example.com', 0, 3600, 3600);
        $this->assertFalse($result6->isValid());

        $result7 = $validator->validate('PC " "', 'host.example.com', 0, 3600, 3600);
        $this->assertFalse($result7->isValid());

        // Invalid quotes
        $result8 = $validator->validate('"PC Linux', 'host.example.com', 0, 3600, 3600);
        $this->assertFalse($result8->isValid());

        $result9 = $validator->validate('PC" Linux', 'host.example.com', 0, 3600, 3600);
        $this->assertFalse($result9->isValid());

        // Too long fields
        $longField = str_repeat('a', 1001);
        $result10 = $validator->validate("$longField Linux", 'host.example.com', 0, 3600, 3600);
        $this->assertFalse($result10->isValid());

        $result11 = $validator->validate("PC $longField", 'host.example.com', 0, 3600, 3600);
        $this->assertFalse($result11->isValid());

        // Invalid hostname
        $result12 = $validator->validate('PC Linux', 'invalid..hostname', 0, 3600, 3600);
        $this->assertFalse($result12->isValid());
    }
}
