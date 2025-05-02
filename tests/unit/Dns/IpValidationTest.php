<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;

/**
 * Tests for IP address validation in the Dns class
 */
class IpValidationTest extends BaseDnsTest
{
    private IPAddressValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new IPAddressValidator();
    }

    /**
     * Test IPv4 validation
     */
    public function testValidateIPv4()
    {
        $this->assertTrue($this->validator->isValidIPv4("192.168.1.1", false));
        $this->assertFalse($this->validator->isValidIPv4("not_an_ip", false));
    }

    /**
     * Test IPv6 validation
     */
    public function testValidateIPv6()
    {
        $this->assertTrue($this->validator->isValidIPv6("2001:db8::1"));
        $this->assertFalse($this->validator->isValidIPv6("not_an_ipv6"));
    }

    /**
     * Test multiple IP validation
     */
    public function testValidateMultipleIPs()
    {
        $this->assertTrue($this->validator->areMultipleValidIPs("192.168.1.1, 10.0.0.1"));
        $this->assertFalse($this->validator->areMultipleValidIPs("192.168.1.1, invalid_ip"));
    }
}
