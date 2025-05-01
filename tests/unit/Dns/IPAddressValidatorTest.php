<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;

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

    public function testIsValidIPv4()
    {
        // Valid IPv4 addresses
        $this->assertTrue($this->validator->isValidIPv4("192.168.1.1", false));
        $this->assertTrue($this->validator->isValidIPv4("127.0.0.1", false));
        $this->assertTrue($this->validator->isValidIPv4("0.0.0.0", false));
        $this->assertTrue($this->validator->isValidIPv4("255.255.255.255", false));

        // Invalid IPv4 addresses
        $this->assertFalse($this->validator->isValidIPv4("256.0.0.1", false));
        $this->assertFalse($this->validator->isValidIPv4("192.168.1", false));
        $this->assertFalse($this->validator->isValidIPv4("192.168.1.1.5", false));
        $this->assertFalse($this->validator->isValidIPv4("192.168.1.a", false));
        $this->assertFalse($this->validator->isValidIPv4("not_an_ip", false));
        $this->assertFalse($this->validator->isValidIPv4("", false));
    }

    public function testIsValidIPv6()
    {
        // Valid IPv6 addresses
        $this->assertTrue($this->validator->isValidIPv6("2001:db8::1"));
        $this->assertTrue($this->validator->isValidIPv6("::1"));
        $this->assertTrue($this->validator->isValidIPv6("2001:db8:0:0:0:0:0:1"));
        $this->assertTrue($this->validator->isValidIPv6("2001:db8::"));
        $this->assertTrue($this->validator->isValidIPv6("fe80::1ff:fe23:4567:890a"));

        // Invalid IPv6 addresses
        $this->assertFalse($this->validator->isValidIPv6("2001:db8:::1"));
        $this->assertFalse($this->validator->isValidIPv6("2001:db8:g::1"));
        $this->assertFalse($this->validator->isValidIPv6("not_an_ipv6"));
        $this->assertFalse($this->validator->isValidIPv6("192.168.1.1"));
        $this->assertFalse($this->validator->isValidIPv6(""));
    }

    public function testAreMultipleValidIPs()
    {
        // Valid multiple IP combinations
        $this->assertTrue($this->validator->areMultipleValidIPs("192.168.1.1"));
        $this->assertTrue($this->validator->areMultipleValidIPs("192.168.1.1, 10.0.0.1"));
        $this->assertTrue($this->validator->areMultipleValidIPs("2001:db8::1"));
        $this->assertTrue($this->validator->areMultipleValidIPs("192.168.1.1, 2001:db8::1"));
        $this->assertTrue($this->validator->areMultipleValidIPs("192.168.1.1, 10.0.0.1, 2001:db8::1, fe80::1"));

        // Invalid multiple IP combinations
        $this->assertFalse($this->validator->areMultipleValidIPs("192.168.1.1, invalid_ip"));
        $this->assertFalse($this->validator->areMultipleValidIPs("invalid_ip"));
        $this->assertFalse($this->validator->areMultipleValidIPs("192.168.1.1, 300.0.0.1"));
        $this->assertFalse($this->validator->areMultipleValidIPs("192.168.1.1, 2001:zz8::1"));
        $this->assertFalse($this->validator->areMultipleValidIPs(""));
    }
}
