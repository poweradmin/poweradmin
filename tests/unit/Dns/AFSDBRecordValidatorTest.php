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
use Poweradmin\Domain\Service\DnsValidation\AFSDBRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the AFSDBRecordValidator
 */
class AFSDBRecordValidatorTest extends TestCase
{
    private AFSDBRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new AFSDBRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'afs.example.com';
        $name = 'example.com';
        $prio = 1; // AFS cell database server
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(1, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithValidSubtypeTwoData()
    {
        $content = 'dce.example.com';
        $name = 'example.com';
        $prio = 2; // DCE authenticated name server
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(2, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '-invalid-hostname.example.com'; // Invalid hostname
        $name = 'example.com';
        $prio = 1;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidDomainName()
    {
        $content = 'valid.example.com';
        $name = '-invalid-domain.com'; // Invalid domain name
        $prio = 1;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidSubtype()
    {
        $content = 'afs.example.com';
        $name = 'example.com';
        $prio = 3; // Invalid subtype (only 1 and 2 are valid)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('subtype', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'afs.example.com';
        $name = 'example.com';
        $prio = 1;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithEmptySubtype()
    {
        $content = 'afs.example.com';
        $name = 'example.com';
        $prio = ''; // Empty subtype should default to 1
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(1, $data['prio']); // Should default to 1
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'afs.example.com';
        $name = 'example.com';
        $prio = 1;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
