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

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\NSECRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class NSECRecordValidatorTest extends TestCase
{
    private NSECRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new NSECRecordValidator($this->configMock);
    }

    /**
     * Test validation with valid NSEC content
     */
    public function testValidateWithValidNSECContent(): void
    {
        $result = $this->validator->validate(
            'example.com. A NS SOA MX TXT AAAA',  // content
            'host.example.com',                   // name
            '',                                   // prio (empty as not used for NSEC)
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('example.com. A NS SOA MX TXT AAAA', $result['content']);
        $this->assertEquals(3600, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }

    /**
     * Test validation with only next domain name (no type map)
     */
    public function testValidateWithOnlyNextDomainName(): void
    {
        $result = $this->validator->validate(
            'example.com.',                       // content (just the next domain)
            'host.example.com',                   // name
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('example.com.', $result['content']);
        $this->assertEquals(3600, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        $result = $this->validator->validate(
            '',                                   // empty content
            'host.example.com',                   // name
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with invalid next domain name
     */
    public function testValidateWithInvalidNextDomainName(): void
    {
        $result = $this->validator->validate(
            'invalid..domain. A NS SOA',          // invalid content (bad domain)
            'host.example.com',                   // name
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with invalid record type in the type map
     */
    public function testValidateWithInvalidRecordType(): void
    {
        $result = $this->validator->validate(
            'example.com. A NS INVALID-TYPE SOA',  // content with invalid type
            'host.example.com',                    // name
            '',                                    // prio
            3600,                                  // ttl
            86400                                  // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with numeric type codes (should pass)
     */
    public function testValidateWithNumericTypeCodes(): void
    {
        $result = $this->validator->validate(
            'example.com. 1 2 6 15 16 28',        // content with numeric type codes
            'host.example.com',                   // name
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('example.com. 1 2 6 15 16 28', $result['content']);
        $this->assertEquals(3600, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }

    /**
     * Test validation with type parameters in parentheses
     */
    public function testValidateWithTypeParameters(): void
    {
        $result = $this->validator->validate(
            'example.com. A(1) NS(2) SOA(6)',     // content with type parameters
            'host.example.com',                   // name
            '',                                   // prio
            3600,                                 // ttl
            86400                                 // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('example.com. A(1) NS(2) SOA(6)', $result['content']);
        $this->assertEquals(3600, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        $result = $this->validator->validate(
            'example.com. A NS SOA',              // content
            'host.example.com',                   // name
            '',                                   // prio
            -1,                                   // invalid ttl
            86400                                 // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with default TTL
     */
    public function testValidateWithDefaultTtl(): void
    {
        $result = $this->validator->validate(
            'example.com. A NS SOA',              // content
            'host.example.com',                   // name
            '',                                   // prio
            '',                                   // empty ttl, should use default
            86400                                 // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('example.com. A NS SOA', $result['content']);
        $this->assertEquals(86400, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }
}
