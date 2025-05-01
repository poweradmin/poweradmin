<?php

namespace unit\Dns;

use Poweradmin\Domain\Service\Dns;

/**
 * Tests for IP address validation
 */
class IpValidationTest extends BaseDnsTest
{
    public function testIsValidIPv4()
    {
        // Valid IPv4 addresses
        $this->assertTrue(Dns::is_valid_ipv4("192.168.1.1", false));
        $this->assertTrue(Dns::is_valid_ipv4("127.0.0.1", false));
        $this->assertTrue(Dns::is_valid_ipv4("0.0.0.0", false));
        $this->assertTrue(Dns::is_valid_ipv4("255.255.255.255", false));

        // Invalid IPv4 addresses
        $this->assertFalse(Dns::is_valid_ipv4("256.0.0.1", false));
        $this->assertFalse(Dns::is_valid_ipv4("192.168.1", false));
        $this->assertFalse(Dns::is_valid_ipv4("192.168.1.1.5", false));
        $this->assertFalse(Dns::is_valid_ipv4("192.168.1.a", false));
        $this->assertFalse(Dns::is_valid_ipv4("not_an_ip", false));
        $this->assertFalse(Dns::is_valid_ipv4("", false));
    }

    public function testIsValidIPv6()
    {
        // Valid IPv6 addresses
        $this->assertTrue(Dns::is_valid_ipv6("2001:db8::1"));
        $this->assertTrue(Dns::is_valid_ipv6("::1"));
        $this->assertTrue(Dns::is_valid_ipv6("2001:db8:0:0:0:0:0:1"));
        $this->assertTrue(Dns::is_valid_ipv6("2001:db8::"));
        $this->assertTrue(Dns::is_valid_ipv6("fe80::1ff:fe23:4567:890a"));

        // Invalid IPv6 addresses
        $this->assertFalse(Dns::is_valid_ipv6("2001:db8:::1"));
        $this->assertFalse(Dns::is_valid_ipv6("2001:db8:g::1"));
        $this->assertFalse(Dns::is_valid_ipv6("not_an_ipv6"));
        $this->assertFalse(Dns::is_valid_ipv6("192.168.1.1"));
        $this->assertFalse(Dns::is_valid_ipv6(""));
    }

    public function testAreMultipleValidIps()
    {
        // Valid multiple IP combinations
        $this->assertTrue(Dns::are_multiple_valid_ips("192.168.1.1"));
        $this->assertTrue(Dns::are_multiple_valid_ips("192.168.1.1, 10.0.0.1"));
        $this->assertTrue(Dns::are_multiple_valid_ips("2001:db8::1"));
        $this->assertTrue(Dns::are_multiple_valid_ips("192.168.1.1, 2001:db8::1"));
        $this->assertTrue(Dns::are_multiple_valid_ips("192.168.1.1, 10.0.0.1, 2001:db8::1, fe80::1"));

        // Invalid multiple IP combinations
        $this->assertFalse(Dns::are_multiple_valid_ips("192.168.1.1, invalid_ip"));
        $this->assertFalse(Dns::are_multiple_valid_ips("invalid_ip"));
        $this->assertFalse(Dns::are_multiple_valid_ips("192.168.1.1, 300.0.0.1"));
        $this->assertFalse(Dns::are_multiple_valid_ips("192.168.1.1, 2001:zz8::1"));
        $this->assertFalse(Dns::are_multiple_valid_ips(""));
    }
}
