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
use Poweradmin\Domain\Service\DnsValidation\NSEC3RecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class NSEC3RecordValidatorTest extends TestCase
{
    private NSEC3RecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new NSEC3RecordValidator($this->configMock);
    }

    /**
     * Test validation with valid NSEC3 content
     */
    public function testValidateWithValidNSEC3Content(): void
    {
        $result = $this->validator->validate(
            '1 0 10 AB12CD 01234ABCDEF A NS SOA MX TXT AAAA',  // content
            'hash.example.com',                                 // name
            '',                                                 // prio (empty as not used for NSEC3)
            3600,                                               // ttl
            86400                                               // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('1 0 10 AB12CD 01234ABCDEF A NS SOA MX TXT AAAA', $result['content']);
        $this->assertEquals(3600, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }

    /**
     * Test validation with minimum required fields (no type maps)
     */
    public function testValidateWithMinimumRequiredFields(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH',                        // content (no type maps)
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('1 0 5 - AB12CD34EF56GH', $result['content']);
        $this->assertEquals(3600, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        $result = $this->validator->validate(
            '',                                              // empty content
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with too few fields
     */
    public function testValidateWithTooFewFields(): void
    {
        $result = $this->validator->validate(
            '1 0 5 -',                                       // missing next hashed owner
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with invalid hash algorithm
     */
    public function testValidateWithInvalidAlgorithm(): void
    {
        $result = $this->validator->validate(
            '2 0 5 - AB12CD34EF56GH',                        // invalid algorithm (2)
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with invalid flags
     */
    public function testValidateWithInvalidFlags(): void
    {
        $result = $this->validator->validate(
            '1 2 5 - AB12CD34EF56GH',                        // invalid flags (2)
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with too many iterations
     */
    public function testValidateWithTooManyIterations(): void
    {
        $result = $this->validator->validate(
            '1 0 3000 - AB12CD34EF56GH',                     // too many iterations
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with invalid salt
     */
    public function testValidateWithInvalidSalt(): void
    {
        $result = $this->validator->validate(
            '1 0 5 GZ AB12CD34EF56GH',                       // invalid salt (non-hex)
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with invalid next hashed owner
     */
    public function testValidateWithInvalidNextHashedOwner(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - %%%INVALID%%%',                         // invalid next hashed owner
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with invalid record type in the type map
     */
    public function testValidateWithInvalidRecordType(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH A NS INVALID-TYPE',      // invalid type
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with numeric type codes (should pass)
     */
    public function testValidateWithNumericTypeCodes(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH 1 2 6 15 16 28',         // numeric type codes
            'hash.example.com',                               // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('1 0 5 - AB12CD34EF56GH 1 2 6 15 16 28', $result['content']);
        $this->assertEquals(3600, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH A NS SOA',               // content
            'hash.example.com',                               // name
            '',                                               // prio
            -1,                                               // invalid ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with default TTL
     */
    public function testValidateWithDefaultTtl(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - AB12CD34EF56GH A NS SOA',               // content
            'hash.example.com',                               // name
            '',                                               // prio
            '',                                               // empty ttl, should use default
            86400                                             // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('1 0 5 - AB12CD34EF56GH A NS SOA', $result['content']);
        $this->assertEquals(86400, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }
}
