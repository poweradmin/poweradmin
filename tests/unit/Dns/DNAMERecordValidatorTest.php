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
use Poweradmin\Domain\Service\DnsValidation\DNAMERecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the DNAMERecordValidator with ValidationResult pattern
 */
class DNAMERecordValidatorTest extends TestCase
{
    private DNAMERecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new DNAMERecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = 'target.example.com';
        $name = 'source.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);

        // Check warnings are present
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $this->assertIsArray($warnings);
        $this->assertNotEmpty($warnings);

        // Check for RFC 6672 specific warnings
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('singleton rule', $warningText);
        $this->assertStringContainsString('DNAME and CNAME records MUST NOT coexist', $warningText);
        $this->assertStringContainsString('MUST NOT appear at the same owner name as NS records', $warningText);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'target.example.com';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithInvalidTarget()
    {
        $content = '-invalid-target.example.com';  // Invalid target hostname
        $name = 'source.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('DNAME target', $result->getFirstError());
    }

    public function testValidateWithSelfReference()
    {
        $content = 'source.example.com';  // Same as name (self-reference)
        $name = 'source.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('cannot point to itself', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'target.example.com';
        $name = 'source.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = 'target.example.com';
        $name = 'source.example.com';
        $prio = 10;  // Invalid priority for DNAME
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field for DNAME records', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'target.example.com';
        $name = 'source.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testWarningsForZoneApex()
    {
        $content = 'target.example.org';
        $name = 'example.com';  // Zone apex (assuming two-level domain)
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();

        // Check for zone apex specific warning
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('zone apex', $warningText);
        $this->assertStringContainsString('special handling for NS', $warningText);

        // Should not contain NS placement warning for non-apex
        $this->assertStringNotContainsString('MUST NOT appear at the same owner name as NS records', $warningText);
    }

    public function testWarningsForPotentialCircularReferences()
    {
        $content = 'sub.source.example.com';  // Subdomain of the owner name
        $name = 'source.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();

        // Check for circular reference warning
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('circular references', $warningText);
    }
}
