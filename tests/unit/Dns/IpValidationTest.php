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
