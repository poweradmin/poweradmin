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
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * Tests for the IPAddressValidator service
 */
class IPAddressValidatorTest extends TestCase
{
    private IPAddressValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new IPAddressValidator();
    }

    public function testValidateIPv4WithValidAddresses()
    {
        // Valid IPv4 addresses
        $result1 = $this->validator->validateIPv4("192.168.1.1");
        $this->assertTrue($result1->isValid());
        $this->assertEquals("192.168.1.1", $result1->getData());

        $result2 = $this->validator->validateIPv4("127.0.0.1");
        $this->assertTrue($result2->isValid());

        $result3 = $this->validator->validateIPv4("0.0.0.0");
        $this->assertTrue($result3->isValid());

        $result4 = $this->validator->validateIPv4("255.255.255.255");
        $this->assertTrue($result4->isValid());
    }

    public function testValidateIPv4WithInvalidAddresses()
    {
        // Invalid IPv4 addresses
        $result1 = $this->validator->validateIPv4("256.0.0.1");
        $this->assertFalse($result1->isValid());
        $this->assertNotEmpty($result1->getErrors());

        $result2 = $this->validator->validateIPv4("192.168.1");
        $this->assertFalse($result2->isValid());

        $result3 = $this->validator->validateIPv4("192.168.1.1.5");
        $this->assertFalse($result3->isValid());

        $result4 = $this->validator->validateIPv4("192.168.1.a");
        $this->assertFalse($result4->isValid());

        $result5 = $this->validator->validateIPv4("not_an_ip");
        $this->assertFalse($result5->isValid());

        $result6 = $this->validator->validateIPv4("");
        $this->assertFalse($result6->isValid());
    }

    public function testValidateIPv6WithValidAddresses()
    {
        // Valid IPv6 addresses
        $result1 = $this->validator->validateIPv6("2001:db8::1");
        $this->assertTrue($result1->isValid());
        $this->assertEquals("2001:db8::1", $result1->getData());

        $result2 = $this->validator->validateIPv6("::1");
        $this->assertTrue($result2->isValid());

        $result3 = $this->validator->validateIPv6("2001:db8:0:0:0:0:0:1");
        $this->assertTrue($result3->isValid());

        $result4 = $this->validator->validateIPv6("2001:db8::");
        $this->assertTrue($result4->isValid());

        $result5 = $this->validator->validateIPv6("fe80::1ff:fe23:4567:890a");
        $this->assertTrue($result5->isValid());
    }

    public function testValidateIPv6WithInvalidAddresses()
    {
        // Invalid IPv6 addresses
        $result1 = $this->validator->validateIPv6("2001:db8:::1");
        $this->assertFalse($result1->isValid());
        $this->assertNotEmpty($result1->getErrors());

        $result2 = $this->validator->validateIPv6("2001:db8:g::1");
        $this->assertFalse($result2->isValid());

        $result3 = $this->validator->validateIPv6("not_an_ipv6");
        $this->assertFalse($result3->isValid());

        $result4 = $this->validator->validateIPv6("192.168.1.1");
        $this->assertFalse($result4->isValid());

        $result5 = $this->validator->validateIPv6("");
        $this->assertFalse($result5->isValid());
    }

    public function testValidateMultipleIPsWithValidCombinations()
    {
        // Valid multiple IP combinations
        $result1 = $this->validator->validateMultipleIPs("192.168.1.1");
        $this->assertTrue($result1->isValid());
        $this->assertCount(1, $result1->getData());

        $result2 = $this->validator->validateMultipleIPs("192.168.1.1, 10.0.0.1");
        $this->assertTrue($result2->isValid());
        $this->assertCount(2, $result2->getData());

        $result3 = $this->validator->validateMultipleIPs("2001:db8::1");
        $this->assertTrue($result3->isValid());

        $result4 = $this->validator->validateMultipleIPs("192.168.1.1, 2001:db8::1");
        $this->assertTrue($result4->isValid());
        $this->assertCount(2, $result4->getData());

        $result5 = $this->validator->validateMultipleIPs("192.168.1.1, 10.0.0.1, 2001:db8::1, fe80::1");
        $this->assertTrue($result5->isValid());
        $this->assertCount(4, $result5->getData());
    }

    public function testValidateMultipleIPsWithInvalidCombinations()
    {
        // Invalid multiple IP combinations
        $result1 = $this->validator->validateMultipleIPs("192.168.1.1, invalid_ip");
        $this->assertFalse($result1->isValid());
        $this->assertNotEmpty($result1->getErrors());

        $result2 = $this->validator->validateMultipleIPs("invalid_ip");
        $this->assertFalse($result2->isValid());

        $result3 = $this->validator->validateMultipleIPs("192.168.1.1, 300.0.0.1");
        $this->assertFalse($result3->isValid());

        $result4 = $this->validator->validateMultipleIPs("192.168.1.1, 2001:zz8::1");
        $this->assertFalse($result4->isValid());

        $result5 = $this->validator->validateMultipleIPs("");
        $this->assertFalse($result5->isValid());
    }

    public function testIsValidIPv4WithValidAddresses()
    {
        // Valid IPv4 addresses
        $this->assertTrue($this->validator->isValidIPv4("192.168.1.1"));
        $this->assertTrue($this->validator->isValidIPv4("127.0.0.1"));
        $this->assertTrue($this->validator->isValidIPv4("0.0.0.0"));
        $this->assertTrue($this->validator->isValidIPv4("255.255.255.255"));
    }

    public function testIsValidIPv4WithInvalidAddresses()
    {
        // Invalid IPv4 addresses
        $this->assertFalse($this->validator->isValidIPv4("256.0.0.1"));
        $this->assertFalse($this->validator->isValidIPv4("192.168.1"));
        $this->assertFalse($this->validator->isValidIPv4("192.168.1.1.5"));
        $this->assertFalse($this->validator->isValidIPv4("192.168.1.a"));
        $this->assertFalse($this->validator->isValidIPv4("not_an_ip"));
        $this->assertFalse($this->validator->isValidIPv4(""));
    }

    public function testIsValidIPv6WithValidAddresses()
    {
        // Valid IPv6 addresses
        $this->assertTrue($this->validator->isValidIPv6("2001:db8::1"));
        $this->assertTrue($this->validator->isValidIPv6("::1"));
        $this->assertTrue($this->validator->isValidIPv6("2001:db8:0:0:0:0:0:1"));
        $this->assertTrue($this->validator->isValidIPv6("2001:db8::"));
        $this->assertTrue($this->validator->isValidIPv6("fe80::1ff:fe23:4567:890a"));
    }

    public function testIsValidIPv6WithInvalidAddresses()
    {
        // Invalid IPv6 addresses
        $this->assertFalse($this->validator->isValidIPv6("2001:db8:::1"));
        $this->assertFalse($this->validator->isValidIPv6("2001:db8:g::1"));
        $this->assertFalse($this->validator->isValidIPv6("not_an_ipv6"));
        $this->assertFalse($this->validator->isValidIPv6("192.168.1.1"));
        $this->assertFalse($this->validator->isValidIPv6(""));
    }

    public function testValidateIPv6CanonicalForm()
    {
        // Not canonical: leading zeros
        $result = $this->validator->validateIPv6('2001:0db8::1', true);
        $this->assertTrue($result->isValid());
        $this->assertEquals('2001:db8::1', $result->getData());

        // Not canonical: uppercase
        $result = $this->validator->validateIPv6('2001:DB8::1', true);
        $this->assertTrue($result->isValid());
        $this->assertEquals('2001:db8::1', $result->getData());

        // Not canonical: needs compression
        $result = $this->validator->validateIPv6('2001:db8:0:0:0:0:0:1', true);
        $this->assertTrue($result->isValid());
        $this->assertEquals('2001:db8::1', $result->getData());

        // Test case for the bug we fixed (starts with zeros)
        $result = $this->validator->validateIPv6('0:0:0:0:0:0:0:1', true);
        $this->assertTrue($result->isValid());
        $this->assertEquals('::1', $result->getData());

        // Correct canonical form
        $result = $this->validator->validateIPv6('2001:db8::1', true);
        $this->assertTrue($result->isValid());
        $this->assertEquals('2001:db8::1', $result->getData());
    }

    // IP:port format tests (PowerDNS compatibility)

    public function testValidateMultipleIPsWithIPv4Port()
    {
        // Single IPv4 with port
        $result1 = $this->validator->validateMultipleIPs("192.0.2.1:5300");
        $this->assertTrue($result1->isValid());
        $this->assertCount(1, $result1->getData());
        $this->assertEquals(["192.0.2.1:5300"], $result1->getData());

        // Multiple IPv4 with ports
        $result2 = $this->validator->validateMultipleIPs("192.0.2.1:5300, 192.0.2.2:5301");
        $this->assertTrue($result2->isValid());
        $this->assertCount(2, $result2->getData());

        // IPv4 with standard DNS port
        $result3 = $this->validator->validateMultipleIPs("192.0.2.1:53");
        $this->assertTrue($result3->isValid());

        // IPv4 with high port number
        $result4 = $this->validator->validateMultipleIPs("192.0.2.1:65535");
        $this->assertTrue($result4->isValid());

        // IPv4 with low port number
        $result5 = $this->validator->validateMultipleIPs("192.0.2.1:1");
        $this->assertTrue($result5->isValid());
    }

    public function testValidateMultipleIPsWithIPv6Port()
    {
        // Single IPv6 with port
        $result1 = $this->validator->validateMultipleIPs("[2001:db8::1]:5300");
        $this->assertTrue($result1->isValid());
        $this->assertCount(1, $result1->getData());
        $this->assertEquals(["[2001:db8::1]:5300"], $result1->getData());

        // Multiple IPv6 with ports
        $result2 = $this->validator->validateMultipleIPs("[2001:db8::1]:5300, [2001:db8::2]:5301");
        $this->assertTrue($result2->isValid());
        $this->assertCount(2, $result2->getData());

        // IPv6 with standard DNS port
        $result3 = $this->validator->validateMultipleIPs("[::1]:53");
        $this->assertTrue($result3->isValid());

        // IPv6 with high port number
        $result4 = $this->validator->validateMultipleIPs("[fe80::1]:65535");
        $this->assertTrue($result4->isValid());

        // Compressed IPv6 with port
        $result5 = $this->validator->validateMultipleIPs("[::]:5300");
        $this->assertTrue($result5->isValid());
    }

    public function testValidateMultipleIPsWithMixedFormats()
    {
        // Mix of IPv4 with and without port
        $result1 = $this->validator->validateMultipleIPs("192.0.2.1, 192.0.2.2:5300");
        $this->assertTrue($result1->isValid());
        $this->assertCount(2, $result1->getData());

        // Mix of IPv6 with and without port
        $result2 = $this->validator->validateMultipleIPs("2001:db8::1, [2001:db8::2]:5300");
        $this->assertTrue($result2->isValid());
        $this->assertCount(2, $result2->getData());

        // Mix of IPv4 and IPv6 with ports
        $result3 = $this->validator->validateMultipleIPs("192.0.2.1:5300, [2001:db8::1]:5301");
        $this->assertTrue($result3->isValid());
        $this->assertCount(2, $result3->getData());

        // Mix of all formats
        $result4 = $this->validator->validateMultipleIPs("192.0.2.1, 192.0.2.2:5300, 2001:db8::1, [2001:db8::2]:5301");
        $this->assertTrue($result4->isValid());
        $this->assertCount(4, $result4->getData());
    }

    public function testValidateMultipleIPsWithInvalidPorts()
    {
        // Port too high
        $result1 = $this->validator->validateMultipleIPs("192.0.2.1:99999");
        $this->assertFalse($result1->isValid());
        $this->assertNotEmpty($result1->getErrors());

        // Port zero
        $result2 = $this->validator->validateMultipleIPs("192.0.2.1:0");
        $this->assertFalse($result2->isValid());

        // Negative port
        $result3 = $this->validator->validateMultipleIPs("192.0.2.1:-1");
        $this->assertFalse($result3->isValid());

        // IPv6 with port too high
        $result4 = $this->validator->validateMultipleIPs("[2001:db8::1]:70000");
        $this->assertFalse($result4->isValid());

        // IPv6 with port zero
        $result5 = $this->validator->validateMultipleIPs("[2001:db8::1]:0");
        $this->assertFalse($result5->isValid());

        // Port is not a number
        $result6 = $this->validator->validateMultipleIPs("192.0.2.1:abc");
        $this->assertFalse($result6->isValid());
    }

    public function testValidateMultipleIPsWithInvalidPortFormats()
    {
        // IPv4 with port but invalid IP
        $result1 = $this->validator->validateMultipleIPs("300.0.0.1:5300");
        $this->assertFalse($result1->isValid());
        $this->assertNotEmpty($result1->getErrors());

        // IPv6 with unclosed bracket
        $result2 = $this->validator->validateMultipleIPs("[2001:db8::1:5300");
        $this->assertFalse($result2->isValid());

        // IPv6 with unopened bracket
        $result3 = $this->validator->validateMultipleIPs("2001:db8::1]:5300");
        $this->assertFalse($result3->isValid());

        // Invalid IP with port
        $result4 = $this->validator->validateMultipleIPs("invalid:5300");
        $this->assertFalse($result4->isValid());

        // Multiple colons in IPv4
        $result5 = $this->validator->validateMultipleIPs("192.0.2.1:53:00");
        $this->assertFalse($result5->isValid());

        // IPv6 with invalid characters in brackets
        $result6 = $this->validator->validateMultipleIPs("[2001:gg8::1]:5300");
        $this->assertFalse($result6->isValid());
    }

    public function testValidateMultipleIPsWithEdgeCases()
    {
        // Whitespace handling with ports
        $result1 = $this->validator->validateMultipleIPs(" 192.0.2.1:5300 , 192.0.2.2:5301 ");
        $this->assertTrue($result1->isValid());
        $this->assertCount(2, $result1->getData());

        // Empty entries with ports
        $result2 = $this->validator->validateMultipleIPs("192.0.2.1:5300,,192.0.2.2:5301");
        $this->assertTrue($result2->isValid());
        $this->assertCount(2, $result2->getData());

        // Loopback with port
        $result3 = $this->validator->validateMultipleIPs("127.0.0.1:8080");
        $this->assertTrue($result3->isValid());

        // IPv6 loopback with port
        $result4 = $this->validator->validateMultipleIPs("[::1]:8080");
        $this->assertTrue($result4->isValid());

        // Private network with port
        $result5 = $this->validator->validateMultipleIPs("10.0.0.1:5300, 172.16.0.1:5301, 192.168.1.1:5302");
        $this->assertTrue($result5->isValid());
        $this->assertCount(3, $result5->getData());
    }

    public function testValidateMultipleIPsBackwardCompatibility()
    {
        // Ensure old tests still pass - bare IPs without ports
        $result1 = $this->validator->validateMultipleIPs("192.0.2.1");
        $this->assertTrue($result1->isValid());

        $result2 = $this->validator->validateMultipleIPs("192.0.2.1, 192.0.2.2");
        $this->assertTrue($result2->isValid());

        $result3 = $this->validator->validateMultipleIPs("2001:db8::1");
        $this->assertTrue($result3->isValid());

        $result4 = $this->validator->validateMultipleIPs("192.0.2.1, 2001:db8::1");
        $this->assertTrue($result4->isValid());

        // These should still fail
        $result5 = $this->validator->validateMultipleIPs("invalid");
        $this->assertFalse($result5->isValid());

        $result6 = $this->validator->validateMultipleIPs("");
        $this->assertFalse($result6->isValid());
    }

    public function testAreMultipleValidIPsWithPorts()
    {
        // Test the boolean convenience method with ports
        $this->assertTrue($this->validator->areMultipleValidIPs("192.0.2.1:5300"));
        $this->assertTrue($this->validator->areMultipleValidIPs("[2001:db8::1]:5300"));
        $this->assertTrue($this->validator->areMultipleValidIPs("192.0.2.1, 192.0.2.2:5300"));
        $this->assertFalse($this->validator->areMultipleValidIPs("192.0.2.1:99999"));
        $this->assertFalse($this->validator->areMultipleValidIPs("invalid:5300"));
    }
}
