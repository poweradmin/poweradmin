<?php

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
}
