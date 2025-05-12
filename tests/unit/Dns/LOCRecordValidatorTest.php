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
use Poweradmin\Domain\Service\DnsValidation\LOCRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the LOCRecordValidator
 */
class LOCRecordValidatorTest extends TestCase
{
    private LOCRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new LOCRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '37 46 30.000 N 122 23 30.000 W 0.00m 1m 10000m 10m';
        $name = 'geo.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']); // LOC always uses 0
        $this->assertEquals(3600, $data['ttl']);

        // Check that warnings are present
        $this->assertTrue($result->hasWarnings());
        $this->assertIsArray($result->getWarnings());
        $this->assertGreaterThan(0, count($result->getWarnings()));

        // Check for expected warning about experimental status
        $foundExperimentalWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'experimental protocol') !== false) {
                $foundExperimentalWarning = true;
                break;
            }
        }
        $this->assertTrue($foundExperimentalWarning, 'Warning about experimental status not found');

        // Check for warning about default precision values
        $foundPrecisionWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'Default precision values') !== false) {
                $foundPrecisionWarning = true;
                break;
            }
        }
        $this->assertTrue($foundPrecisionWarning, 'Warning about default precision values not found');
    }

    public function testValidateWithSimpleCoordinates()
    {
        $content = '37 N 122 W 0m';
        $name = 'geo.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);

        $this->assertEquals($name, $data['name']);
        $data = $result->getData();

        $this->assertEquals(0, $data['prio']);

        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '37 46 30.000 N 122 23 30.000 W 0.00m 1m 10000m 10m';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidLOCFormat()
    {
        $content = '37 46 30.000 X 122 23 30.000 W 0.00m 1m 10000m 10m'; // Invalid direction (X instead of N/S)
        $name = 'geo.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidLatitude()
    {
        $content = '91 46 30.000 N 122 23 30.000 W 0.00m 1m 10000m 10m'; // Invalid latitude (over 90 degrees)
        $name = 'geo.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        // The validator properly rejects values over 90 degrees for latitude
        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Latitude degrees must be between 0 and 90', $result->getFirstError());
    }

    public function testValidateWithExtremeLatitude()
    {
        $content = '90 0 0 N 122 23 30.000 W 0.00m 1m 10000m 10m'; // North Pole
        $name = 'geo.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();

        // Check for North Pole warning
        $foundPoleWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'North Pole') !== false) {
                $foundPoleWarning = true;
                break;
            }
        }
        $this->assertTrue($foundPoleWarning, 'Warning about North Pole latitude not found');
    }

    public function testValidateWithInvalidLongitude()
    {
        $content = '37 46 30.000 N 181 23 30.000 W 0.00m 1m 10000m 10m'; // Invalid longitude (over 180 degrees)
        $name = 'geo.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Longitude degrees must be between 0 and 180', $result->getFirstError());
    }

    public function testValidateWithInvalidDirectionMarker()
    {
        $content = '37 46 30.000 X 122 23 30.000 W 0.00m 1m 10000m 10m'; // Invalid direction (X instead of N/S)
        $name = 'geo.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        // Should fail because X is not a valid direction marker
        $this->assertStringContainsString('Latitude direction must be specified as N or S', $result->getFirstError());
    }

    public function testValidateWithInvalidMinutes()
    {
        $content = '37 60 30.000 N 122 23 30.000 W 0.00m 1m 10000m 10m'; // Invalid minutes (60 is too high)
        $name = 'geo.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Minutes must be between 0 and 59', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '37 46 30.000 N 122 23 30.000 W 0.00m 1m 10000m 10m';
        $name = 'geo.example.com';
        $prio = '';
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '37 46 30.000 N 122 23 30.000 W 0.00m 1m 10000m 10m';
        $name = 'geo.example.com';
        $prio = '';
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithNonZeroPriority()
    {
        $content = '37 46 30.000 N 122 23 30.000 W 0.00m 1m 10000m 10m';
        $name = 'geo.example.com';
        $prio = 10; // Non-zero priority (should be rejected for LOC records)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Priority field for LOC records must be 0 or empty', $result->getFirstError());
    }

    public function testValidateWithInvalidAltitude()
    {
        $content = '37 46 30.000 N 122 23 30.000 W -100001m 1m 10000m 10m'; // Invalid altitude (below allowed minimum)
        $name = 'geo.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Altitude must be between -100000 and 42849672.95', $result->getFirstError());
    }

    public function testValidateWithZeroLatitude()
    {
        $content = '0 0 0 N 122 23 30.000 W 0.00m 1m 10000m 10m'; // Equator
        $name = 'geo.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();

        // Check for Equator warning
        $foundEquatorWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'Zero latitude') !== false) {
                $foundEquatorWarning = true;
                break;
            }
        }
        $this->assertTrue($foundEquatorWarning, 'Warning about Equator latitude not found');
    }
}
