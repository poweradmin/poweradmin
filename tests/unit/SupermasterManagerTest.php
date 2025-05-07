<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;

class SupermasterManagerTest extends TestCase
{
    public function testAddSupermaster()
    {
        // Create a real IPAddressValidator instance
        $ipValidator = new IPAddressValidator();

        // Test the isValidIPv4 and isValidIPv6 methods
        $this->assertTrue($ipValidator->isValidIPv4('127.0.0.1'));
        $this->assertFalse($ipValidator->isValidIPv4('not-an-ip'));
        $this->assertTrue($ipValidator->isValidIPv6('2001:0db8:85a3:0000:0000:8a2e:0370:7334'));
        $this->assertFalse($ipValidator->isValidIPv6('not-an-ipv6'));
    }
}
