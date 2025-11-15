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
}
