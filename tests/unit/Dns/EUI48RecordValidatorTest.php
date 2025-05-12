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
use Poweradmin\Domain\Service\DnsValidation\EUI48RecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the EUI48RecordValidator with ValidationResult pattern
 */
class EUI48RecordValidatorTest extends TestCase
{
    private EUI48RecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new EUI48RecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '00-11-22-33-44-55';  // Valid MAC address format
        $name = 'host.example.com';
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

        // Check for warnings
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $this->assertIsArray($warnings);
        $this->assertNotEmpty($warnings);

        // Check for RFC reference in warning
        $warningsText = implode(' ', $warnings);
        $this->assertStringContainsString('RFC 7043', $warningsText);
        $this->assertStringContainsString('privacy', $warningsText);
    }

    public function testValidateWithValidUppercaseHexData()
    {
        $content = '00-1A-2B-3C-4D-5E';  // Valid MAC address with uppercase hex
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']); // Case should be preserved as per RFC 7043
    }

    public function testValidateWithColonFormatError()
    {
        $content = '00:11:22:33:44:55';  // Using colons instead of hyphens
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // Should have specific error for colon format
        $this->assertStringContainsString('colon separators', $result->getFirstError());
    }

    public function testValidateWithNoSeparators()
    {
        $content = '001122334455';  // No separators
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // Should have specific error for missing separators
        $this->assertStringContainsString('missing separators', $result->getFirstError());
    }

    public function testValidateWithCiscoFormat()
    {
        $content = '0011.2233.4455';  // Cisco format
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // Should have specific error for Cisco format
        $this->assertStringContainsString('Cisco format', $result->getFirstError());
    }

    public function testValidateWithInvalidEUI48Values()
    {
        $content = '00-11-22-33-44-GG';  // 'GG' is not a valid hex value
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithInvalidEUI48Length()
    {
        $content = '00-11-22-33-44';  // Too short
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithMulticastAddress()
    {
        $content = '01-11-22-33-44-55';  // First bit is 1 (multicast)
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningsText = implode(' ', $warnings);

        // Should warn about multicast address
        $this->assertStringContainsString('multicast address', $warningsText);
    }

    public function testValidateWithLocallyAdministeredAddress()
    {
        $content = '02-11-22-33-44-55';  // Second bit is 1 (locally administered)
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningsText = implode(' ', $warnings);

        // Should warn about locally administered address
        $this->assertStringContainsString('locally administered address', $warningsText);
    }

    public function testValidateWithAllZerosAddress()
    {
        $content = '00-00-00-00-00-00';  // All zeros
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningsText = implode(' ', $warnings);

        // Should warn about all-zeros address
        $this->assertStringContainsString('all-zeros address', $warningsText);
    }

    public function testValidateWithBroadcastAddress()
    {
        $content = 'FF-FF-FF-FF-FF-FF';  // Broadcast address
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningsText = implode(' ', $warnings);

        // Should warn about broadcast address
        $this->assertStringContainsString('broadcast address', $warningsText);
    }

    public function testValidateWithIANAAddress()
    {
        $content = '00-00-5E-00-00-01';  // IANA OUI address
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningsText = implode(' ', $warnings);

        // Should note IANA address
        $this->assertStringContainsString('IANA OUI', $warningsText);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '00-11-22-33-44-55';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '00-11-22-33-44-55';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '00-11-22-33-44-55';
        $name = 'host.example.com';
        $prio = 10;  // Non-zero priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority must be 0', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '00-11-22-33-44-55';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }
}
