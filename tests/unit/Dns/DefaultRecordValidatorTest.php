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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DefaultRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class DefaultRecordValidatorTest extends TestCase
{
    private DefaultRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new DefaultRecordValidator($this->configMock);
    }

    /**
     * Test validation with valid inputs
     */
    public function testValidateWithValidInputs(): void
    {
        $result = $this->validator->validate(
            'valid.content.example.com',  // content
            'record.example.com',        // name
            0,                          // prio
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals('valid.content.example.com', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['prio']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        $result = $this->validator->validate(
            '',                         // empty content
            'record.example.com',       // name
            0,                          // prio
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Content field cannot be empty', $result->getFirstError());
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        $result = $this->validator->validate(
            'valid.content.example.com',  // content
            'record.example.com',        // name
            0,                          // prio
            -1,                         // invalid ttl
            86400                       // defaultTTL
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
            'valid.content.example.com',  // content
            'record.example.com',        // name
            0,                          // prio
            '',                         // empty ttl, should use default
            86400                       // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('valid.content.example.com', $data['content']);
        $this->assertEquals(86400, $data['ttl']);
        $this->assertEquals(0, $data['prio']);
    }

    /**
     * Test validation with custom priority
     */
    public function testValidateWithCustomPriority(): void
    {
        $result = $this->validator->validate(
            'valid.content.example.com',  // content
            'record.example.com',        // name
            10,                         // custom prio
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals('valid.content.example.com', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(10, $data['prio']);
    }

    /**
     * Test validation with empty priority (should default to 0)
     */
    public function testValidateWithEmptyPriority(): void
    {
        $result = $this->validator->validate(
            'valid.content.example.com',  // content
            'record.example.com',        // name
            '',                         // empty prio
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('valid.content.example.com', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['prio']);
    }
}
