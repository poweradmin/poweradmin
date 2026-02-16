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

namespace Tests\Unit\Domain\Utility;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\IpHelper;

class IpHelperTest extends TestCase
{
    /**
     * Test extracting single IPv4 address
     */
    public function testExtractFirstIpFromMasterSingleIpv4(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('192.168.1.1');
        $this->assertEquals('192.168.1.1', $result);
    }

    /**
     * Test extracting single IPv6 address
     */
    public function testExtractFirstIpFromMasterSingleIpv6(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('2001:db8::1');
        $this->assertEquals('2001:db8::1', $result);
    }

    /**
     * Test extracting IPv4 with port notation
     */
    public function testExtractFirstIpFromMasterIpv4WithPort(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('192.168.1.1:5300');
        $this->assertEquals('192.168.1.1', $result);
    }

    /**
     * Test extracting IPv6 with port notation (bracketed)
     */
    public function testExtractFirstIpFromMasterIpv6WithPort(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('[2001:db8::1]:5300');
        $this->assertEquals('2001:db8::1', $result);
    }

    /**
     * Test extracting IPv6 with brackets but no port
     */
    public function testExtractFirstIpFromMasterIpv6WithBracketsNoPort(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('[2001:db8::1]');
        $this->assertEquals('2001:db8::1', $result);
    }

    /**
     * Test extracting first IP from multiple comma-separated IPs
     */
    public function testExtractFirstIpFromMasterMultipleIps(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('192.168.1.1,192.168.1.2,192.168.1.3');
        $this->assertEquals('192.168.1.1', $result);
    }

    /**
     * Test extracting first IP from multiple IPs with ports
     */
    public function testExtractFirstIpFromMasterMultipleIpsWithPorts(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('192.168.1.1:5300,192.168.1.2:5301');
        $this->assertEquals('192.168.1.1', $result);
    }

    /**
     * Test extracting IP with leading/trailing whitespace
     */
    public function testExtractFirstIpFromMasterWithWhitespace(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('  192.168.1.1  ');
        $this->assertEquals('192.168.1.1', $result);
    }

    /**
     * Test extracting IP from comma-separated list with spaces
     */
    public function testExtractFirstIpFromMasterMultipleIpsWithSpaces(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('192.168.1.1 , 192.168.1.2 , 192.168.1.3');
        $this->assertEquals('192.168.1.1', $result);
    }

    /**
     * Test that hostnames are ignored
     */
    public function testExtractFirstIpFromMasterIgnoresHostname(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('ns1.example.com');
        $this->assertNull($result);
    }

    /**
     * Test that hostname followed by IP returns the IP
     */
    public function testExtractFirstIpFromMasterHostnameThenIp(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('ns1.example.com,192.168.1.1');
        $this->assertEquals('192.168.1.1', $result);
    }

    /**
     * Test empty string
     */
    public function testExtractFirstIpFromMasterEmptyString(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('');
        $this->assertNull($result);
    }

    /**
     * Test whitespace only
     */
    public function testExtractFirstIpFromMasterWhitespaceOnly(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('   ');
        $this->assertNull($result);
    }

    /**
     * Test invalid IP format
     */
    public function testExtractFirstIpFromMasterInvalidIp(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('999.999.999.999');
        $this->assertNull($result);
    }

    /**
     * Test mixed IPv4 and IPv6
     */
    public function testExtractFirstIpFromMasterMixedIpv4IPv6(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('192.168.1.1,2001:db8::1');
        $this->assertEquals('192.168.1.1', $result);
    }

    /**
     * Test IPv6 first in mixed list
     */
    public function testExtractFirstIpFromMasterIpv6FirstInMixedList(): void
    {
        $result = IpHelper::extractFirstIpFromMaster('2001:db8::1,192.168.1.1');
        $this->assertEquals('2001:db8::1', $result);
    }

    /**
     * Test extracting all IPs from single IP
     */
    public function testExtractAllIpsFromMasterSingleIp(): void
    {
        $result = IpHelper::extractAllIpsFromMaster('192.168.1.1');
        $this->assertEquals(['192.168.1.1'], $result);
    }

    /**
     * Test extracting all IPs from multiple IPs
     */
    public function testExtractAllIpsFromMasterMultipleIps(): void
    {
        $result = IpHelper::extractAllIpsFromMaster('192.168.1.1,192.168.1.2,192.168.1.3');
        $this->assertEquals(['192.168.1.1', '192.168.1.2', '192.168.1.3'], $result);
    }

    /**
     * Test extracting all IPs with ports
     */
    public function testExtractAllIpsFromMasterWithPorts(): void
    {
        $result = IpHelper::extractAllIpsFromMaster('192.168.1.1:5300,192.168.1.2:5301');
        $this->assertEquals(['192.168.1.1', '192.168.1.2'], $result);
    }

    /**
     * Test extracting all IPs ignores hostnames
     */
    public function testExtractAllIpsFromMasterIgnoresHostnames(): void
    {
        $result = IpHelper::extractAllIpsFromMaster('ns1.example.com,192.168.1.1,ns2.example.com,192.168.1.2');
        $this->assertEquals(['192.168.1.1', '192.168.1.2'], $result);
    }

    /**
     * Test extracting all IPs from empty string
     */
    public function testExtractAllIpsFromMasterEmptyString(): void
    {
        $result = IpHelper::extractAllIpsFromMaster('');
        $this->assertEquals([], $result);
    }

    /**
     * Test extracting all IPs with mixed IPv4 and IPv6
     */
    public function testExtractAllIpsFromMasterMixedIpv4IPv6(): void
    {
        $result = IpHelper::extractAllIpsFromMaster('192.168.1.1,[2001:db8::1]:5300,192.168.1.2');
        $this->assertEquals(['192.168.1.1', '2001:db8::1', '192.168.1.2'], $result);
    }

    /**
     * Test complex real-world scenario
     */
    public function testExtractFirstIpFromMasterComplexRealWorld(): void
    {
        // Common PowerDNS master configurations
        $testCases = [
            '192.168.1.1' => '192.168.1.1',
            '192.168.1.1:53' => '192.168.1.1',
            '192.168.1.1, 192.168.1.2' => '192.168.1.1',
            ' 192.168.1.1 ' => '192.168.1.1',
            '2001:db8::1' => '2001:db8::1',
            '[2001:db8::1]:53' => '2001:db8::1',
            'ns1.example.com, 192.168.1.1' => '192.168.1.1',
            '192.168.1.1:5300, [2001:db8::1]:5300' => '192.168.1.1',
        ];

        foreach ($testCases as $input => $expected) {
            $result = IpHelper::extractFirstIpFromMaster($input);
            $this->assertEquals($expected, $result, "Failed for input: $input");
        }
    }

    /**
     * Test shortening full IPv6 reverse zone
     */
    public function testShortenIPv6ReverseZoneFullAddress(): void
    {
        $input = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $result = IpHelper::shortenIPv6ReverseZone($input);
        $this->assertEquals('2001:db8::1:0', $result);
    }

    /**
     * Test shortening IPv6 reverse zone with all zeros
     */
    public function testShortenIPv6ReverseZoneAllZeros(): void
    {
        $input = '0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $result = IpHelper::shortenIPv6ReverseZone($input);
        $this->assertEquals('2001:db8::', $result);
    }

    /**
     * Test shortening partial IPv6 reverse zone (/64 network)
     */
    public function testShortenIPv6ReverseZonePartial64(): void
    {
        $input = '1.1.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $result = IpHelper::shortenIPv6ReverseZone($input);
        // Partial zones get padded with zeros
        $this->assertEquals('2001:db8:11::', $result);
    }

    /**
     * Test shortening partial IPv6 reverse zone (/48 network)
     */
    public function testShortenIPv6ReverseZonePartial48(): void
    {
        $input = '8.b.d.0.1.0.0.2.ip6.arpa';
        $result = IpHelper::shortenIPv6ReverseZone($input);
        $this->assertEquals('2001:db8::', $result);
    }

    /**
     * Test shortening IPv6 reverse zone with maximum compression
     */
    public function testShortenIPv6ReverseZoneMaxCompression(): void
    {
        $input = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa';
        $result = IpHelper::shortenIPv6ReverseZone($input);
        $this->assertEquals('::1', $result);
    }

    /**
     * Test shortening IPv6 reverse zone - loopback
     */
    public function testShortenIPv6ReverseZoneLoopback(): void
    {
        $input = '0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa';
        $result = IpHelper::shortenIPv6ReverseZone($input);
        $this->assertEquals('::', $result);
    }

    /**
     * Test shortening IPv6 reverse zone with no compression needed
     */
    public function testShortenIPv6ReverseZoneNoCompression(): void
    {
        $input = 'f.e.d.c.b.a.9.8.7.6.5.4.3.2.1.0.f.e.d.c.b.a.9.8.7.6.5.4.3.2.1.0.ip6.arpa';
        $result = IpHelper::shortenIPv6ReverseZone($input);
        $this->assertEquals('123:4567:89ab:cdef:123:4567:89ab:cdef', $result);
    }

    /**
     * Test shortening invalid zone (not ending in .ip6.arpa)
     */
    public function testShortenIPv6ReverseZoneInvalidSuffix(): void
    {
        $input = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.in-addr.arpa';
        $result = IpHelper::shortenIPv6ReverseZone($input);
        $this->assertNull($result);
    }

    /**
     * Test shortening invalid zone (invalid hex characters)
     */
    public function testShortenIPv6ReverseZoneInvalidHex(): void
    {
        $input = 'g.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $result = IpHelper::shortenIPv6ReverseZone($input);
        $this->assertNull($result);
    }

    /**
     * Test shortening invalid zone (nibbles not single character)
     */
    public function testShortenIPv6ReverseZoneInvalidNibbleLength(): void
    {
        $input = '10.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $result = IpHelper::shortenIPv6ReverseZone($input);
        $this->assertNull($result);
    }

    /**
     * Test shortening with real-world examples
     */
    public function testShortenIPv6ReverseZoneRealWorld(): void
    {
        $testCases = [
            // Google Public DNS
            '8.8.8.8.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.4.6.8.2.ip6.arpa' => '2864::8888',
            // Cloudflare DNS
            '1.1.1.1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.6.6.8.2.ip6.arpa' => '2866::1111',
            // Documentation prefix (nibbles 0.0.0.1 reversed = 1.0.0.0 = 0x1000)
            '0.0.0.1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa' => '2001:db8::1000:0',
        ];

        foreach ($testCases as $input => $expected) {
            $result = IpHelper::shortenIPv6ReverseZone($input);
            $this->assertEquals($expected, $result, "Failed for input: $input");
        }
    }

    /**
     * Test that shortening is idempotent (can be called multiple times)
     */
    public function testShortenIPv6ReverseZoneIdempotent(): void
    {
        $input = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $result1 = IpHelper::shortenIPv6ReverseZone($input);

        // Second call with same input should return same result
        $result2 = IpHelper::shortenIPv6ReverseZone($input);
        $this->assertEquals($result1, $result2);
    }

    /**
     * Test shortening a full expanded IPv6 address
     */
    public function testShortenIPv6AddressFullExpanded(): void
    {
        $result = IpHelper::shortenIPv6Address('2001:0db8:0000:0000:0000:0000:0000:0001');
        $this->assertEquals('2001:db8::1', $result);
    }

    /**
     * Test shortening an already compressed IPv6 address
     */
    public function testShortenIPv6AddressAlreadyCompressed(): void
    {
        $result = IpHelper::shortenIPv6Address('2001:db8::1');
        $this->assertEquals('2001:db8::1', $result);
    }

    /**
     * Test shortening IPv6 loopback address
     */
    public function testShortenIPv6AddressLoopback(): void
    {
        $result = IpHelper::shortenIPv6Address('0000:0000:0000:0000:0000:0000:0000:0001');
        $this->assertEquals('::1', $result);
    }

    /**
     * Test shortening IPv6 all zeros
     */
    public function testShortenIPv6AddressAllZeros(): void
    {
        $result = IpHelper::shortenIPv6Address('0000:0000:0000:0000:0000:0000:0000:0000');
        $this->assertEquals('::', $result);
    }

    /**
     * Test shortening IPv6 address with leading zeros in groups
     */
    public function testShortenIPv6AddressLeadingZeros(): void
    {
        $result = IpHelper::shortenIPv6Address('2001:0db8:0085:0000:0000:0008:0800:0200');
        $this->assertEquals('2001:db8:85::8:800:200', $result);
    }

    /**
     * Test shortening IPv6 address returns original for invalid input
     */
    public function testShortenIPv6AddressInvalidReturnsOriginal(): void
    {
        $result = IpHelper::shortenIPv6Address('not-an-ipv6-address');
        $this->assertEquals('not-an-ipv6-address', $result);
    }

    /**
     * Test shortening IPv6 address returns original for IPv4 address
     */
    public function testShortenIPv6AddressIPv4ReturnsOriginal(): void
    {
        $result = IpHelper::shortenIPv6Address('192.168.1.1');
        $this->assertEquals('192.168.1.1', $result);
    }

    /**
     * Test shortening IPv6 address with empty string
     */
    public function testShortenIPv6AddressEmptyString(): void
    {
        $result = IpHelper::shortenIPv6Address('');
        $this->assertEquals('', $result);
    }

    /**
     * Test shortening IPv6 address with whitespace
     */
    public function testShortenIPv6AddressWithWhitespace(): void
    {
        $result = IpHelper::shortenIPv6Address('  2001:0db8:0000:0000:0000:0000:0000:0001  ');
        $this->assertEquals('2001:db8::1', $result);
    }

    /**
     * Test shortening IPv6 address - real world examples
     */
    public function testShortenIPv6AddressRealWorld(): void
    {
        $testCases = [
            // Google Public DNS
            '2001:4860:4860:0000:0000:0000:0000:8888' => '2001:4860:4860::8888',
            // Cloudflare DNS
            '2606:4700:4700:0000:0000:0000:0000:1111' => '2606:4700:4700::1111',
            // Link-local address
            'fe80:0000:0000:0000:0000:0000:0000:0001' => 'fe80::1',
            // Full address with no compression needed
            '2001:db8:85a3:8d3:1319:8a2e:370:7348' => '2001:db8:85a3:8d3:1319:8a2e:370:7348',
        ];

        foreach ($testCases as $input => $expected) {
            $result = IpHelper::shortenIPv6Address($input);
            $this->assertEquals($expected, $result, "Failed for input: $input");
        }
    }

    /**
     * Test that shortenIPv6Address is idempotent
     */
    public function testShortenIPv6AddressIdempotent(): void
    {
        $input = '2001:0db8:0000:0000:0000:0000:0000:0001';
        $result1 = IpHelper::shortenIPv6Address($input);
        $result2 = IpHelper::shortenIPv6Address($result1);
        $this->assertEquals($result1, $result2);
    }
}
