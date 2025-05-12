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
     * Test validation with valid NID value and preference in plain hex format
     */
    public function testValidateWithValidNIDValue(): void
    {
        $result = $this->validator->validate(
            '1234567890ABCDEF',  // content (16 hex chars) - using plain hex format
            'nid.example.com',   // name
            20,                  // preference
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        // The content should be converted to RFC 6742 presentation format with colons
        $this->assertEquals('1234:5678:90AB:CDEF', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(20, $data['priority']);
        $this->assertTrue($result->hasWarnings());
        // Should have warning about plain format vs. RFC presentation format
        $foundWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'presentation format') !== false) {
                $foundWarning = true;
                break;
            }
        }
        $this->assertTrue($foundWarning, 'Warning about presentation format not found');

        // Should have the raw NodeID for reference
        $this->assertEquals('1234567890ABCDEF', $data['raw_node_id']);
    }

    /**
     * Test validation with valid NID value in RFC 6742 presentation format
     */
    public function testValidateWithRFC6742PresentationFormat(): void
    {
        $result = $this->validator->validate(
            '1234:5678:90AB:CDEF',  // content in RFC 6742 presentation format
            'nid.example.com',      // name
            20,                     // preference
            3600,                   // ttl
            86400                   // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1234:5678:90AB:CDEF', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(20, $data['priority']);

        // No warning about presentation format since it's already in the correct format
        $foundWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'presentation format') !== false) {
                $foundWarning = true;
                break;
            }
        }
        $this->assertFalse($foundWarning, 'Should not have warning about presentation format');
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
        $this->assertEquals('1234:5678:90AB:CDEF', $data['content']);
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
        $this->assertEquals('1234:5678:90AB:CDEF', $data['content']);
        $this->assertEquals(86400, $data['ttl']);
        $this->assertEquals(20, $data['priority']);
    }

    /**
     * Test validation of NodeID with invalid Group bit (should be 0)
     */
    public function testValidateWithInvalidGroupBit(): void
    {
        // 0100007890ABCDEF has the Group bit (LSB of first byte) set to 1,
        // which is invalid according to RFC 6742
        $result = $this->validator->validate(
            '0100007890ABCDEF',  // content with invalid Group bit
            'nid.example.com',   // name
            20,                  // preference
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Group bit', $result->getFirstError());
    }

    /**
     * Test validation with zero-padded groups in presentation format
     */
    public function testValidateWithZeroPaddedGroups(): void
    {
        $result = $this->validator->validate(
            '1:2:3:4',           // short form that needs zero-padding
            'nid.example.com',   // name
            20,                  // preference
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        // Should be formatted with proper zero-padding
        $this->assertEquals('0001:0002:0003:0004', $data['content']);
    }

    /**
     * Test validation with universal/local bit set to universal (0)
     */
    public function testValidateWithUniversalBit(): void
    {
        // Set the universal/local bit to 0 (universal)
        // The u/l bit is the second bit of the first byte, so first byte = 00000000
        $result = $this->validator->validate(
            '0000567890ABCDEF',  // content with u/l bit = 0
            'nid.example.com',   // name
            20,                  // preference
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Should have a warning about the universal bit
        $foundWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'universal') !== false) {
                $foundWarning = true;
                break;
            }
        }
        $this->assertTrue($foundWarning, 'Warning about universal bit not found');
    }

    /**
     * Test validation with universal/local bit set to local (1)
     */
    public function testValidateWithLocalBit(): void
    {
        // Set the universal/local bit to 1 (local)
        // The u/l bit is the second bit of the first byte, so first byte = 00000010
        $result = $this->validator->validate(
            '0200567890ABCDEF',  // content with u/l bit = 1
            'nid.example.com',   // name
            20,                  // preference
            3600,                // ttl
            86400                // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Should have a warning about the local bit
        $foundWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'local') !== false) {
                $foundWarning = true;
                break;
            }
        }
        $this->assertTrue($foundWarning, 'Warning about local bit not found');
    }
}
