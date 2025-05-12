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
use Poweradmin\Domain\Service\DnsValidation\L64RecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the L64RecordValidator
 */
class L64RecordValidatorTest extends TestCase
{
    private L64RecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new L64RecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '10 2001:0db8:1140:1000';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']); // Using provided prio value
        $this->assertEquals(3600, $data['ttl']);

        // Check that warnings are present
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertGreaterThan(0, count($warnings));

        // Check for expected warning about experimental status
        $foundExperimentalWarning = false;
        foreach ($warnings as $warning) {
            if (strpos($warning, 'experimental protocol') !== false) {
                $foundExperimentalWarning = true;
                break;
            }
        }
        $this->assertTrue($foundExperimentalWarning, 'Warning about experimental status not found');
    }

    public function testValidateWithProvidedPriority()
    {
        $content = '10 2001:0db8:1140:1000';
        $name = 'host.example.com';
        $prio = 20; // This should override the content's preference
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(20, $data['prio']); // Should use provided prio
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithAnotherValidLocator()
    {
        $content = '20 fedc:ba98:7654:3210';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithInvalidPreference()
    {
        $content = '65536 2001:0db8:1140:1000'; // Preference > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('preference must be a number between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithInvalidLocator()
    {
        $content = '10 2001:0db8:1140:GGGG'; // Invalid hex characters
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('locator must be a valid 64-bit hexadecimal', $result->getFirstError());
    }

    public function testValidateWithIPv4AsLocator()
    {
        $content = '10 192.0.2.1'; // IPv4 not allowed for L64
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('locator must be a valid 64-bit hexadecimal', $result->getFirstError());
    }

    public function testValidateWithWrongNumberOfSegments()
    {
        $content = '10 2001:0db8:1140'; // Not enough segments
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('locator must be a valid 64-bit hexadecimal', $result->getFirstError());
    }

    public function testValidateWithTooManySegments()
    {
        $content = '10 2001:0db8:1140:1000:abcd'; // Too many segments
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('locator must be a valid 64-bit hexadecimal', $result->getFirstError());
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '10'; // Missing locator
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain preference and locator64 separated by space', $result->getFirstError());
    }

    public function testValidateWithTooManyParts()
    {
        $content = '10 2001:0db8:1140:1000 extrapart'; // Too many parts
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain preference and locator64 separated by space', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '10 2001:0db8:1140:1000';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('hostname', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 2001:0db8:1140:1000';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 2001:0db8:1140:1000';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);

        // Check for TTL warning for mobile nodes
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $foundTtlWarning = false;
        foreach ($warnings as $warning) {
            if (strpos($warning, 'very low TTL values') !== false) {
                $foundTtlWarning = true;
                break;
            }
        }
        $this->assertTrue($foundTtlWarning, 'Warning about TTL values for mobile nodes not found');
    }

    public function testValidateWithNegativePreference()
    {
        $content = '-1 2001:0db8:1140:1000'; // Negative preference not allowed
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('preference must be a number between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithWildcardName()
    {
        $content = '10 2001:0db8:1140:1000';
        $name = '*.example.com'; // Wildcard DNS entry
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        // Check for wildcard warning
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $foundWildcardWarning = false;
        foreach ($warnings as $warning) {
            if (strpos($warning, 'wildcard DNS entries') !== false) {
                $foundWildcardWarning = true;
                break;
            }
        }
        $this->assertTrue($foundWildcardWarning, 'Warning about wildcard DNS entries not found');
    }

    public function testValidateWithAllZerosLocator()
    {
        $content = '10 0000:0000:0000:0000'; // All zeros
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('unspecified', $result->getFirstError());
    }

    public function testValidateWithAllOnesLocator()
    {
        $content = '10 ffff:ffff:ffff:ffff'; // All ones
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('all-ones', $result->getFirstError());
    }

    public function testValidateWithSegmentTooLong()
    {
        $content = '10 2001:0db8:11400:1000'; // Third segment has 5 hex digits
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('hexadecimal IPv6 address segment', $result->getFirstError());
    }
}
