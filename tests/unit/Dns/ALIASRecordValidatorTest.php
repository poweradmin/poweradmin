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
use Poweradmin\Domain\Service\DnsValidation\ALIASRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the ALIASRecordValidator using ValidationResult pattern
 */
class ALIASRecordValidatorTest extends TestCase
{
    private ALIASRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new ALIASRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid(), "ALIAS record validation should succeed for valid data");
        $data = $result->getData();

        $this->assertIsArray($data, "ValidationResult data should be an array");
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);

        // For TTL, check its value in the nested array
        $this->assertArrayHasKey('ttl', $data);
        $this->assertEquals(3600, $data['ttl'], "TTL should be properly validated");
    }

    public function testValidateWithInvalidSourceHostname()
    {
        $content = 'target.example.com';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid(), "ALIAS record validation should fail for invalid source hostname");
        $this->assertNotEmpty($result->getErrors(), "Should have error messages for invalid hostname");
    }

    public function testValidateWithInvalidTargetHostname()
    {
        $content = '-invalid-target.example.com'; // Invalid target hostname
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid(), "ALIAS record validation should fail for invalid target hostname");
        $this->assertNotEmpty($result->getErrors(), "Should have error messages for invalid target hostname");
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid(), "ALIAS record validation should fail for invalid TTL");
        $this->assertNotEmpty($result->getErrors(), "Should have error messages for invalid TTL");
    }

    public function testValidateWithInvalidPriority()
    {
        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 10; // Invalid priority for ALIAS record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid(), "ALIAS record validation should fail for invalid priority");
        $this->assertNotEmpty($result->getErrors(), "Should have error messages for invalid priority");
    }

    public function testValidateWithEmptyPriority()
    {
        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid(), "ALIAS record validation should succeed with empty priority");
        $data = $result->getData();
        $this->assertEquals(0, $data['prio'], "Empty priority should default to 0");
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid(), "ALIAS record validation should succeed with empty TTL");
        $data = $result->getData();
        $this->assertIsArray($data);
        $this->assertEquals(86400, $data['ttl'], "Empty TTL should use default value");
    }
}
