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
use Poweradmin\Domain\Service\DnsValidation\CDSRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the CDSRecordValidator
 */
class CDSRecordValidatorTest extends TestCase
{
    private CDSRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new CDSRecordValidator($this->configMock);
    }

    public function testValidateWithValidSHA1Data()
    {
        $content = '12345 13 1 1234567890123456789012345678901234567890';  // 40 hex chars for SHA-1
        $name = 'example.com';  // Apex record
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);

        // Check that we have warnings due to SHA-1 usage
        $this->assertNotEmpty($result->getWarnings());
        $this->assertIsArray($result->getWarnings());

        // Check that we have a warning about SHA-1 being deprecated
        $shaShaWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'SHA-1 (digest type 1) is deprecated') !== false) {
                $shaShaWarningFound = true;
                break;
            }
        }
        $this->assertTrue($shaShaWarningFound, 'Should warn about SHA-1 being deprecated');
    }

    public function testValidateWithValidSHA256Data()
    {
        $content = '12345 13 2 1234567890123456789012345678901234567890123456789012345678901234';  // 64 hex chars for SHA-256
        $name = 'example.com';  // Apex record
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);

        // Check that warnings don't mention SHA-256 being deprecated
        $sha256WarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'SHA-256') !== false && strpos($warning, 'deprecated') !== false) {
                $sha256WarningFound = true;
                break;
            }
        }
        $this->assertFalse($sha256WarningFound, 'Should not warn about SHA-256 being deprecated');
    }

    public function testValidateWithValidSHA384Data()
    {
        $content = '12345 13 4 123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456';  // 96 hex chars for SHA-384
        $name = 'example.com';  // Apex record
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);

        // Check that warnings don't mention SHA-384 being deprecated
        $sha384WarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'SHA-384') !== false && strpos($warning, 'deprecated') !== false) {
                $sha384WarningFound = true;
                break;
            }
        }
        $this->assertFalse($sha384WarningFound, 'Should not warn about SHA-384 being deprecated');
    }

    public function testValidateWithDeletionRecord()
    {
        $content = '0 0 0 00';
        $name = 'example.com';  // Apex record
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);

        // Check that we have a warning about this being a deletion record
        $deletionWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'This is a CDS deletion record') !== false) {
                $deletionWarningFound = true;
                break;
            }
        }
        $this->assertTrue($deletionWarningFound, 'Should warn about deletion record significance');
    }

    public function testValidateWithInvalidKeyTag()
    {
        $content = '99999999 13 1 1234567890123456789012345678901234567890';  // Key tag > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '12345 99 1 1234567890123456789012345678901234567890';  // Algorithm > 16
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidDigestType()
    {
        $content = '12345 13 3 1234567890123456789012345678901234567890';  // Digest type 3 is invalid
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidDigestLength()
    {
        $content = '12345 13 1 123456';  // Too short for SHA-1
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidDigestCharacters()
    {
        $content = '12345 13 1 123456789012345678901234567890123456789g';  // Contains 'g', not a hex char
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
        $content = '12345 13 1';  // Missing digest
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
        $content = '12345 13 1 1234567890123456789012345678901234567890';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '12345 13 1 1234567890123456789012345678901234567890';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '12345 13 1 1234567890123456789012345678901234567890';
        $name = 'example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithSubdomainName()
    {
        $content = '12345 13 2 1234567890123456789012345678901234567890123456789012345678901234';
        $name = 'sub.domain.example.com';  // Subdomain, not apex
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();

        // Check that we have a warning about apex placement
        $apexWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'should only be placed at the zone apex') !== false) {
                $apexWarningFound = true;
                break;
            }
        }
        $this->assertTrue($apexWarningFound, 'Should warn about non-apex placement');
    }
}
