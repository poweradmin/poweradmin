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
     * Test IPv4 validation with ValidationResult pattern
     */
    public function testValidateIPv4WithValidationResult()
    {
        $result1 = $this->validator->validateIPv4("192.168.1.1");
        $this->assertTrue($result1->isValid());
        $this->assertEquals("192.168.1.1", $result1->getData());

        $result2 = $this->validator->validateIPv4("not_an_ip");
        $this->assertFalse($result2->isValid());
        $this->assertNotEmpty($result2->getErrors());
    }

    /**
     * Test IPv6 validation with ValidationResult pattern
     */
    public function testValidateIPv6WithValidationResult()
    {
        $result1 = $this->validator->validateIPv6("2001:db8::1");
        $this->assertTrue($result1->isValid());
        $this->assertEquals("2001:db8::1", $result1->getData());

        $result2 = $this->validator->validateIPv6("not_an_ipv6");
        $this->assertFalse($result2->isValid());
        $this->assertNotEmpty($result2->getErrors());
    }

    /**
     * Test multiple IP validation with ValidationResult pattern
     */
    public function testValidateMultipleIPsWithValidationResult()
    {
        $result1 = $this->validator->validateMultipleIPs("192.168.1.1, 10.0.0.1");
        $this->assertTrue($result1->isValid());
        $this->assertCount(2, $result1->getData());

        $result2 = $this->validator->validateMultipleIPs("192.168.1.1, invalid_ip");
        $this->assertFalse($result2->isValid());
        $this->assertNotEmpty($result2->getErrors());
    }
}
