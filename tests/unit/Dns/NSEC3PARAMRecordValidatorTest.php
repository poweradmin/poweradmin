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
use Poweradmin\Domain\Service\DnsValidation\NSEC3PARAMRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class NSEC3PARAMRecordValidatorTest extends TestCase
{
    private NSEC3PARAMRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new NSEC3PARAMRecordValidator($this->configMock);
    }

    /**
     * Test validation with valid NSEC3PARAM content
     */
    public function testValidateWithValidNSEC3PARAMContent(): void
    {
        $result = $this->validator->validate(
            '1 0 10 AB12CD',                                  // content
            'example.com',                                    // name
            '',                                               // prio (empty as not used for NSEC3PARAM)
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('1 0 10 AB12CD', $result['content']);
        $this->assertEquals(3600, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }

    /**
     * Test validation with empty salt
     */
    public function testValidateWithEmptySalt(): void
    {
        $result = $this->validator->validate(
            '1 0 5 -',                                        // content with empty salt
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('1 0 5 -', $result['content']);
        $this->assertEquals(3600, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        $result = $this->validator->validate(
            '',                                               // empty content
            'example.com',                                    // name
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
            '1 0 5',                                          // missing salt
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with too many fields
     */
    public function testValidateWithTooManyFields(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - extrafield',                             // too many fields
            'example.com',                                    // name
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
            '2 0 5 -',                                        // invalid algorithm (2)
            'example.com',                                    // name
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
            '1 256 5 -',                                      // invalid flags (256)
            'example.com',                                    // name
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
            '1 0 3000 -',                                     // too many iterations
            'example.com',                                    // name
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
            '1 0 5 GZ',                                       // invalid salt (non-hex)
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result);
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        $result = $this->validator->validate(
            '1 0 5 -',                                        // content
            'example.com',                                    // name
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
            '1 0 5 -',                                        // content
            'example.com',                                    // name
            '',                                               // prio
            '',                                               // empty ttl, should use default
            86400                                             // defaultTTL
        );

        $this->assertIsArray($result);
        $this->assertEquals('1 0 5 -', $result['content']);
        $this->assertEquals(86400, $result['ttl']);
        $this->assertEquals(0, $result['priority']);
    }
}
