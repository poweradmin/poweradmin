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
use Poweradmin\Domain\Service\DnsValidation\DSRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the DSRecordValidator
 */
class DSRecordValidatorTest extends TestCase
{
    private DSRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new DSRecordValidator($this->configMock);
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

        // Check for warnings about recommended algorithms
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $this->assertIsArray($warnings);
        $this->assertNotEmpty($warnings);

        // Verify algorithm warning (algorithm 13 is recommended)
        $warningsText = implode(' ', $warnings);
        $this->assertStringContainsString('RECOMMENDED algorithm', $warningsText);
        $this->assertStringContainsString('ECDSAP256SHA256', $warningsText);

        // Verify SHA-256 warning is present
        $this->assertStringContainsString('SHA-256 (digest type 2)', $warningsText);
    }

    public function testValidateWithZoneApexPlacement()
    {
        $content = '45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'example.com'; // Zone apex
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningsText = implode(' ', $warnings);

        // Should warn about zone apex placement
        $this->assertStringContainsString('not at the zone apex', $warningsText);
        $this->assertStringContainsString('delegation points', $warningsText);
    }

    public function testValidateWithCDSDeletionRecord()
    {
        $content = '0 0 0 00';  // CDS deletion record (RFC 8078)
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningsText = implode(' ', $warnings);

        // Should recognize special CDS record
        $this->assertStringContainsString('special CDS deletion record', $warningsText);
        $this->assertStringContainsString('RFC 8078', $warningsText);
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
        $this->assertStringContainsString('Key tag', $result->getFirstError());
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
        $this->assertStringContainsString('Algorithm', $result->getFirstError());
    }

    public function testValidateWithDeprecatedAlgorithm()
    {
        $content = '45342 1 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningsText = implode(' ', $warnings);

        // Should warn about deprecated algorithm
        $this->assertStringContainsString('NOT RECOMMENDED', $warningsText);
        $this->assertStringContainsString('RSAMD5', $warningsText);
        $this->assertStringContainsString('algorithm 1', $warningsText);
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
        $this->assertStringContainsString('Digest type', $result->getFirstError());
    }

    public function testValidateWithSHA1DigestType()
    {
        // SHA-1 is valid but not recommended
        $content = '45342 13 1 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('SHA-1 digest', $result->getFirstError());

        // Fix digest length
        $content = '45342 13 1 348dedbedc0cddcc4f2605ba42d428223672e5e9';
        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningsText = implode(' ', $warnings);

        // Should warn about SHA-1
        $this->assertStringContainsString('SHA-1 (digest type 1) is NOT RECOMMENDED', $warningsText);
        $this->assertStringContainsString('SHA-256 (digest type 2) is the recommended', $warningsText);
    }

    public function testValidateWithSHA384DigestType()
    {
        // SHA-384 is valid and good for high security
        $content = '45342 13 4 ' . str_repeat('a1b2c3d4', 12); // 96 hex chars
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningsText = implode(' ', $warnings);

        // Should have note about SHA-384
        $this->assertStringContainsString('SHA-384 (digest type 4) is a good choice for higher security', $warningsText);
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
        $this->assertStringContainsString('SHA-256 digest', $result->getFirstError());
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
        $this->assertStringContainsString('hostname', $result->getFirstError());
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
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = '45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $name = 'host.example.com';
        $prio = 10; // Invalid priority for DS record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority', $result->getFirstError());
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

    public function testValidateDSRecordContent()
    {
        // Test valid DS records with exact digest lengths
        $result1 = $this->validator->validateDSRecordContent('45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0');
        $this->assertTrue($result1->isValid());
        $this->assertTrue($result1->hasWarnings());

        $result2 = $this->validator->validateDSRecordContent('15288 5 2 CE0EB9E59EE1DE2C681A330E3A7C08376F28602CDF990EE4EC88D2A8BDB51539');
        $this->assertTrue($result2->isValid());
        $this->assertTrue($result2->hasWarnings());

        // Test warnings for non-recommended algorithm
        $this->assertTrue($result2->hasWarnings());
        $warnings = $result2->getWarnings();
        $warningsText = implode(' ', $warnings);
        $this->assertStringContainsString('NOT RECOMMENDED', $warningsText);
        $this->assertStringContainsString('RSASHA1', $warningsText);

        // Test CDS deletion record
        $result3 = $this->validator->validateDSRecordContent('0 0 0 00');
        $this->assertTrue($result3->isValid());
        $this->assertTrue($result3->hasWarnings());
        $warnings = $result3->getWarnings();
        $warningsText = implode(' ', $warnings);
        $this->assertStringContainsString('special CDS deletion record', $warningsText);

        // Test invalid formats
        $result4 = $this->validator->validateDSRecordContent('45342 13 2');  // Missing digest
        $this->assertFalse($result4->isValid());

        $result5 = $this->validator->validateDSRecordContent('invalid'); // Invalid format
        $this->assertFalse($result5->isValid());

        $result6 = $this->validator->validateDSRecordContent('2371 13 2 1F987CC6583E92DF0890718C42'); // Too short digest
        $this->assertFalse($result6->isValid());

        $result7 = $this->validator->validateDSRecordContent('2371 13 2 1F987CC6583E92DF0890718C42 ; ( SHA1 digest )'); // Extra content
        $this->assertFalse($result7->isValid());
    }
}
