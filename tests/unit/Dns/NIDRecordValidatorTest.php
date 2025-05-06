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
use Poweradmin\Domain\Service\DnsValidation\NIDRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class NIDRecordValidatorTest extends TestCase
{
    private NIDRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new NIDRecordValidator($this->configMock);
    }

    /**
     * Test validation with valid NID value and preference
     */
    public function testValidateWithValidNIDValue(): void
    {
        $result = $this->validator->validate(
            '1234567890ABCDEF',  // content (16 hex chars)
            'nid.example.com',   // name
            20,                  // preference
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1234567890ABCDEF', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(20, $data['priority']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        $result = $this->validator->validate(
            '',                  // empty content
            'nid.example.com',   // name
            20,                  // preference
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('empty', $result->getFirstError());
    }

    /**
     * Test validation with invalid NID value (wrong length)
     */
    public function testValidateWithInvalidNIDLengthValue(): void
    {
        $result = $this->validator->validate(
            '1234567890ABCD',    // invalid content (14 hex chars, should be 16)
            'nid.example.com',   // name
            20,                  // preference
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('64-bit hexadecimal value', $result->getFirstError());
    }

    /**
     * Test validation with invalid NID value (non-hex characters)
     */
    public function testValidateWithInvalidNIDHexValue(): void
    {
        $result = $this->validator->validate(
            '1234567890ABCDEZ',  // invalid content (contains Z which is not hex)
            'nid.example.com',   // name
            20,                  // preference
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('64-bit hexadecimal value', $result->getFirstError());
    }

    /**
     * Test validation with invalid preference (too large)
     */
    public function testValidateWithInvalidPreferenceValue(): void
    {
        $result = $this->validator->validate(
            '1234567890ABCDEF',  // content
            'nid.example.com',   // name
            70000,               // invalid preference (>65535)
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('between 0 and 65535', $result->getFirstError());
    }

    /**
     * Test validation with empty preference (should use default)
     */
    public function testValidateWithEmptyPreference(): void
    {
        $result = $this->validator->validate(
            '1234567890ABCDEF',  // content
            'nid.example.com',   // name
            '',                  // empty preference
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1234567890ABCDEF', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(10, $data['priority']); // Default preference
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        $result = $this->validator->validate(
            '1234567890ABCDEF',  // content
            'nid.example.com',   // name
            20,                  // preference
            -1,                  // invalid ttl
            86400                // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    /**
     * Test validation with default TTL
     */
    public function testValidateWithDefaultTtl(): void
    {
        $result = $this->validator->validate(
            '1234567890ABCDEF',  // content
            'nid.example.com',   // name
            20,                  // preference
            '',                  // empty ttl, should use default
            86400                // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1234567890ABCDEF', $data['content']);
        $this->assertEquals(86400, $data['ttl']);
        $this->assertEquals(20, $data['priority']);
    }
}
