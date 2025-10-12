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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the HostnameValidator service
 */
class HostnameValidatorTest extends TestCase
{
    private HostnameValidator $validator;

    protected function setUp(): void
    {
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns') {
                    if ($key === 'top_level_tld_check') {
                        return false;
                    }
                    if ($key === 'strict_tld_check') {
                        return false;
                    }
                }
                return null;
            });

        $this->validator = new HostnameValidator($configMock);
    }

    /**
     * Test the validate method for hostname validation
     */
    public function testValidateHostname()
    {
        // Valid hostnames
        $validResult = $this->validator->validate('example.com');
        $this->assertTrue($validResult->isValid());
        $this->assertEquals(['hostname' => 'example.com'], $validResult->getData());

        $validResult2 = $this->validator->validate('www.example.com');
        $this->assertTrue($validResult2->isValid());

        // Test with trailing dot (should be normalized)
        $validResult3 = $this->validator->validate('example.com.');
        $this->assertTrue($validResult3->isValid());
        $this->assertEquals(['hostname' => 'example.com'], $validResult3->getData());

        // Invalid hostnames
        $invalidResult = $this->validator->validate('example..com');
        $this->assertFalse($invalidResult->isValid());

        $invalidResult2 = $this->validator->validate('-example.com');
        $this->assertFalse($invalidResult2->isValid());

        $invalidResult3 = $this->validator->validate('example-.com');
        $this->assertFalse($invalidResult3->isValid());

        $tooLongLabel = str_repeat('a', 64) . '.example.com';
        $invalidResult4 = $this->validator->validate($tooLongLabel);
        $this->assertFalse($invalidResult4->isValid());

        // Test wildcard (should fail without wildcard flag)
        $wildcardResult = $this->validator->validate('*.example.com', false);
        $this->assertFalse($wildcardResult->isValid());

        // Test wildcard (should succeed with wildcard flag)
        $wildcardResult2 = $this->validator->validate('*.example.com', true);
        $this->assertTrue($wildcardResult2->isValid());
    }

/**
     * Test the normalizeRecordName function
     */
    public function testNormalizeRecordName()
    {
        // Test case the: Name without zone suffix
        $name = "www";
        $zone = "example.com";
        $expected = "www.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Name already has zone suffix
        $name = "mail.example.com";
        $zone = "example.com";
        $expected = "mail.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Empty name should return zone
        $name = "";
        $zone = "example.com";
        $expected = "example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Case-insensitive matching
        $name = "SUB.EXAMPLE.COM";
        $zone = "example.com";
        $expected = "SUB.EXAMPLE.COM";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Name is @ sign (should be transformed)
        $name = "@";
        $zone = "example.com";
        $expected = "@.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case: Subdomain of zone
        $name = "test.sub";
        $zone = "example.com";
        $expected = "test.sub.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));
    }

    /**
     * Test the endsWith static function
     */
    public function testEndsWith()
    {
        $this->assertTrue(HostnameValidator::endsWith('com', 'example.com'));
        $this->assertTrue(HostnameValidator::endsWith('example.com', 'example.com'));
        $this->assertTrue(HostnameValidator::endsWith('', 'example.com'));

        $this->assertFalse(HostnameValidator::endsWith('test', 'example.com'));
        $this->assertFalse(HostnameValidator::endsWith('com.example', 'example.com'));
        $this->assertFalse(HostnameValidator::endsWith('example.com.org', 'example.com'));
    }

    /**
     * Test the isValid method for hostname validation
     */
    public function testIsValid()
    {
        // Valid hostnames
        $this->assertTrue($this->validator->isValid('example.com'));
        $this->assertTrue($this->validator->isValid('www.example.com'));
        $this->assertTrue($this->validator->isValid('example.com.'));  // With trailing dot
        $this->assertTrue($this->validator->isValid('sub-domain.example.com'));  // With dash
        $this->assertTrue($this->validator->isValid('*.example.com', true));  // With wildcard enabled

        // Invalid hostnames
        $this->assertFalse($this->validator->isValid('example..com'));  // Double dot
        $this->assertFalse($this->validator->isValid('-example.com'));  // Starting with dash
        $this->assertFalse($this->validator->isValid('example-.com'));  // Ending with dash
        $this->assertFalse($this->validator->isValid('*.example.com'));  // Wildcard without flag
        $this->assertFalse($this->validator->isValid(str_repeat('a', 64) . '.example.com'));  // Label too long
        $this->assertFalse($this->validator->isValid('example.com/with/slash'));  // Invalid characters
        $this->assertFalse($this->validator->isValid('example.com!'));  // Invalid characters
    }

    /**
     * Test RFC 2317 classless reverse delegation support
     */
    public function testRFC2317ClasslessReverseDelegation()
    {
        // Valid RFC 2317 zones - IPv4 /26 subnets (64 addresses each)
        $this->assertTrue($this->validator->isValid('0/26.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('64/26.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('128/26.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('192/26.1.0.192.in-addr.arpa'));

        // Valid RFC 2317 zones - IPv4 /27 subnets (32 addresses each)
        $this->assertTrue($this->validator->isValid('0/27.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('32/27.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('64/27.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('96/27.1.0.192.in-addr.arpa'));

        // Valid RFC 2317 zones - IPv4 /28 subnets (16 addresses each)
        $this->assertTrue($this->validator->isValid('0/28.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('16/28.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('32/28.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('240/28.1.0.192.in-addr.arpa'));

        // Valid RFC 2317 zones - IPv4 /29 subnets (8 addresses each)
        $this->assertTrue($this->validator->isValid('0/29.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('8/29.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('248/29.1.0.192.in-addr.arpa'));

        // Valid RFC 2317 zones - IPv4 /30 subnets (4 addresses each)
        $this->assertTrue($this->validator->isValid('0/30.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('4/30.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('252/30.1.0.192.in-addr.arpa'));

        // Valid RFC 2317 zones - IPv4 /31 subnets (2 addresses - point-to-point links)
        $this->assertTrue($this->validator->isValid('0/31.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('2/31.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('254/31.1.0.192.in-addr.arpa'));

        // Valid RFC 2317 zones - IPv4 /25 subnets (128 addresses each)
        $this->assertTrue($this->validator->isValid('0/25.1.0.192.in-addr.arpa'));
        $this->assertTrue($this->validator->isValid('128/25.1.0.192.in-addr.arpa'));

        // Valid RFC 2317 zones - IPv6 with numeric nibbles
        $this->assertTrue($this->validator->isValid('0/64.0.0.0.1.0.0.2.ip6.arpa'));
        $this->assertTrue($this->validator->isValid('0/48.0.0.0.1.0.0.2.ip6.arpa'));

        // Valid RFC 2317 zones - IPv6 with hexadecimal nibbles (a-f)
        $this->assertTrue($this->validator->isValid('a/64.0.0.0.1.0.0.2.ip6.arpa'));
        $this->assertTrue($this->validator->isValid('b/64.0.0.0.1.0.0.2.ip6.arpa'));
        $this->assertTrue($this->validator->isValid('f/64.0.0.0.1.0.0.2.ip6.arpa'));
        $this->assertTrue($this->validator->isValid('A/64.0.0.0.1.0.0.2.ip6.arpa')); // uppercase
        $this->assertTrue($this->validator->isValid('F/64.0.0.0.1.0.0.2.ip6.arpa')); // uppercase
        $this->assertTrue($this->validator->isValid('abc/64.0.0.0.1.0.0.2.ip6.arpa')); // multi-nibble
        $this->assertTrue($this->validator->isValid('deadbeef/64.0.0.0.1.0.0.2.ip6.arpa')); // multi-nibble

        // Invalid RFC 2317 zones - IPv6 with non-hex characters
        $this->assertFalse($this->validator->isValid('g/64.0.0.0.1.0.0.2.ip6.arpa')); // g is not hex
        $this->assertFalse($this->validator->isValid('xyz/64.0.0.0.1.0.0.2.ip6.arpa')); // non-hex

        // Invalid RFC 2317 zones - misaligned subnet boundaries
        $this->assertFalse($this->validator->isValid('1/26.1.0.192.in-addr.arpa'));   // Not multiple of 64
        $this->assertFalse($this->validator->isValid('65/26.1.0.192.in-addr.arpa'));  // Should be 64/26
        $this->assertFalse($this->validator->isValid('127/26.1.0.192.in-addr.arpa')); // Should be 64/26
        $this->assertFalse($this->validator->isValid('3/27.1.0.192.in-addr.arpa'));   // Not multiple of 32
        $this->assertFalse($this->validator->isValid('17/28.1.0.192.in-addr.arpa'));  // Not multiple of 16
        $this->assertFalse($this->validator->isValid('5/29.1.0.192.in-addr.arpa'));   // Not multiple of 8
        $this->assertFalse($this->validator->isValid('3/30.1.0.192.in-addr.arpa'));   // Not multiple of 4
        $this->assertFalse($this->validator->isValid('1/31.1.0.192.in-addr.arpa'));   // Not multiple of 2

        // Invalid RFC 2317 zones - invalid prefix lengths for IPv4
        $this->assertFalse($this->validator->isValid('0/23.1.0.192.in-addr.arpa'));   // Too small (< /24)
        $this->assertFalse($this->validator->isValid('0/16.1.0.192.in-addr.arpa'));   // Too small
        $this->assertFalse($this->validator->isValid('0/33.1.0.192.in-addr.arpa'));   // Too large (> /32)

        // Invalid RFC 2317 zones - invalid subnet numbers for IPv4
        $this->assertFalse($this->validator->isValid('256/26.1.0.192.in-addr.arpa')); // > 255
        $this->assertFalse($this->validator->isValid('-1/26.1.0.192.in-addr.arpa'));  // Negative
        $this->assertFalse($this->validator->isValid('abc/26.1.0.192.in-addr.arpa')); // Non-numeric (IPv4 must be numeric)

        // Invalid RFC 2317 zones - malformed format
        $this->assertFalse($this->validator->isValid('0/26/extra.1.0.192.in-addr.arpa')); // Multiple slashes in one label

        // Note: Dash notation (0-63.1.0.192.in-addr.arpa) is also valid per RFC 2317
        // We accept it as valid since it's a legitimate alternative format

        // Ensure slashes still rejected in non-ARPA zones
        $this->assertFalse($this->validator->isValid('example.com/test'));
        $this->assertFalse($this->validator->isValid('sub/domain.example.com'));
        $this->assertFalse($this->validator->isValid('0/26.example.com'));
    }
}
