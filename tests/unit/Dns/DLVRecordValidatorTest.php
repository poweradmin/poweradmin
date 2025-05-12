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
use Poweradmin\Domain\Service\DnsValidation\DLVRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the DLVRecordValidator with ValidationResult pattern
 */
class DLVRecordValidatorTest extends TestCase
{
    private DLVRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new DLVRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
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

        // Check for obsolescence warning
        $this->assertTrue($result->hasWarnings());
        $this->assertNotEmpty($result->getWarnings());

        // Check for specific obsolescence warning
        $obsoleteWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'obsoleted') !== false) {
                $obsoleteWarningFound = true;
                break;
            }
        }
        $this->assertTrue($obsoleteWarningFound, 'Should warn about DLV being obsoleted by RFC 8749');
    }

    public function testValidateWithRecommendedAlgorithm()
    {
        // Using algorithm 15 (ED25519) which is recommended per RFC 8624
        $content = '45342 15 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        // Check for good algorithm choice warning
        $this->assertTrue($result->hasWarnings());
        $recommendedWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'RECOMMENDED') !== false && strpos($warning, 'Good choice') !== false) {
                $recommendedWarningFound = true;
                break;
            }
        }
        $this->assertTrue($recommendedWarningFound, 'Should acknowledge recommended algorithm choice');
    }

    public function testValidateWithDeprecatedAlgorithm()
    {
        // Using algorithm 1 (RSAMD5) which MUST NOT be used per RFC 8624
        $content = '45342 1 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        // Check for deprecated algorithm warning
        $this->assertTrue($result->hasWarnings());
        $deprecatedWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'MUST NOT be used') !== false) {
                $deprecatedWarningFound = true;
                break;
            }
        }
        $this->assertTrue($deprecatedWarningFound, 'Should warn about deprecated algorithm');
    }

    public function testValidateWithDLVLookupDomain()
    {
        $content = '45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'example.com.dlv.example.org'; // Name in DLV lookup domain
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        // Check for DLV lookup domain warning
        $this->assertTrue($result->hasWarnings());
        $dlvDomainWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'DLV lookup domain') !== false) {
                $dlvDomainWarningFound = true;
                break;
            }
        }
        $this->assertTrue($dlvDomainWarningFound, 'Should warn about DLV lookup domain');
    }

    public function testValidateWithInvalidKeyTag()
    {
        $content = '0 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid key tag', $result->getFirstError());
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '45342 99 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid algorithm', $result->getFirstError());
    }

    public function testValidateWithInvalidDigestType()
    {
        $content = '45342 13 3 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid digest type', $result->getFirstError());
    }

    public function testValidateWithInvalidDigestLength()
    {
        // Test with wrong digest length for SHA-256 (type 2)
        $content = '45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid digest length', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = '-invalid-hostname.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'host.example.com';
        $prio = 10; // Invalid priority for DLV record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field for DLV records', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateDLVContent()
    {
        // Test valid DLV records with exact digest lengths
        $result1 = $this->validator->validateDLVContent('45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0');
        $this->assertTrue($result1->isValid());

        // Should have warnings
        $this->assertTrue($result1->hasWarnings());
        $this->assertNotEmpty($result1->getWarnings());

        $result2 = $this->validator->validateDLVContent('15288 5 2 CE0EB9E59EE1DE2C681A330E3A7C08376F28602CDF990EE4EC88D2A8BDB51539');
        $this->assertTrue($result2->isValid());

        // Check for NOT RECOMMENDED warning for algorithm 5
        $this->assertTrue($result2->hasWarnings());
        $notRecommendedFound = false;
        foreach ($result2->getWarnings() as $warning) {
            if (strpos($warning, 'NOT RECOMMENDED') !== false && strpos($warning, '5') !== false) {
                $notRecommendedFound = true;
                break;
            }
        }
        $this->assertTrue($notRecommendedFound, 'Should warn about not recommended algorithm 5');

        // Test with SHA-1 digest (type 1)
        $result3 = $this->validator->validateDLVContent('12345 8 1 1a2b3c4d5e6f7890abcdef1234567890abcdef12');
        $this->assertTrue($result3->isValid());

        // Check for SHA-1 weakness warning
        $this->assertTrue($result3->hasWarnings());
        $sha1WeaknessFound = false;
        foreach ($result3->getWarnings() as $warning) {
            if (strpos($warning, 'SHA-1') !== false && strpos($warning, 'weak') !== false) {
                $sha1WeaknessFound = true;
                break;
            }
        }
        $this->assertTrue($sha1WeaknessFound, 'Should warn about SHA-1 weakness');

        // Test with SHA-384 digest (type 4)
        $sha384Digest = str_repeat('a1b2c3d4', 12); // 96 characters
        $result4 = $this->validator->validateDLVContent("12345 8 4 $sha384Digest");
        $this->assertTrue($result4->isValid());

        // Test invalid formats
        $result5 = $this->validator->validateDLVContent('45342 13 2');  // Missing digest
        $this->assertFalse($result5->isValid());

        $result6 = $this->validator->validateDLVContent('invalid'); // Invalid format
        $this->assertFalse($result6->isValid());

        $result7 = $this->validator->validateDLVContent('2371 13 2 1F987CC6583E92DF0890718C42'); // Too short digest
        $this->assertFalse($result7->isValid());

        $result8 = $this->validator->validateDLVContent('2371 13 2 1F987CC6583E92DF0890718C42 ; ( SHA1 digest )'); // Extra content
        $this->assertFalse($result8->isValid());

        // Test with non-hex characters in digest
        $result9 = $this->validator->validateDLVContent('2371 13 2 1F987CC6583E92DF0890718C42ZZZZZZ'); // Non-hex characters
        $this->assertFalse($result9->isValid());
    }
}
