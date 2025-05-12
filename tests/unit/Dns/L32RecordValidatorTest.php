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
use Poweradmin\Domain\Service\DnsValidation\L32RecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the L32RecordValidator
 */
class L32RecordValidatorTest extends TestCase
{
    private L32RecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new L32RecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '10 192.0.2.1';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

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
        $content = '10 192.0.2.1';
        $name = 'host.example.com';
        $prio = 20; // This should override the content's preference
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);

        $this->assertEquals($name, $data['name']);
        $data = $result->getData();

        $this->assertEquals(20, $data['prio']); // Using the provided prio value

        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithAnotherValidIPAddress()
    {
        $content = '20 172.16.254.1';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);

        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithInvalidPreference()
    {
        $content = '65536 192.0.2.1'; // Preference > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidLocator()
    {
        $content = '10 192.0.2.300'; // Invalid IPv4 address
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithIPv6AsLocator()
    {
        $content = '10 2001:db8::1'; // IPv6 not allowed for L32
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
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


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithTooManyParts()
    {
        $content = '10 192.0.2.1 extrapart'; // Too many parts
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '10 192.0.2.1';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '10 192.0.2.1';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '10 192.0.2.1';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

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
        $content = '-1 192.0.2.1'; // Negative preference not allowed
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testPrivateIPAddressWarning()
    {
        $content = '10 192.168.1.1'; // Private IP address
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        // Check for private IP address warning
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $foundPrivateIPWarning = false;
        foreach ($warnings as $warning) {
            if (strpos($warning, 'private IP addresses') !== false) {
                $foundPrivateIPWarning = true;
                break;
            }
        }
        $this->assertTrue($foundPrivateIPWarning, 'Warning about private IP addresses not found');
    }

    public function testValidateWithInvalidSpecialAddresses()
    {
        $content = '10 0.0.0.0'; // Invalid special address
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('broadcast or unspecified', $result->getErrors()[0]);
    }
}
