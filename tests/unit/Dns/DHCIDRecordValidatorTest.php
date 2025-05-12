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
use Poweradmin\Domain\Service\DnsValidation\DHCIDRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the DHCIDRecordValidator
 */
class DHCIDRecordValidatorTest extends TestCase
{
    private DHCIDRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new DHCIDRecordValidator($this->configMock);
    }

    /**
     * Create a properly formatted DHCID record for testing
     *
     * This creates a valid DHCID record with:
     * - Identifier type: 0x0001 (DHCPv4 client identifier)
     * - Digest type: 0x01 (SHA-256)
     * - Some random digest data of the correct length
     *
     * @return string Base64-encoded DHCID data
     */
    private function createValidDHCID(): string
    {
        // Create a 35-byte binary string
        // First 2 bytes: identifier type (0x0001)
        // Third byte: digest type (0x01)
        // Remaining 32 bytes: random data for SHA-256 digest
        $binary = pack('n', 0x0001) . chr(1) . random_bytes(32);

        // Base64 encode and return
        return base64_encode($binary);
    }

    public function testValidateWithValidData()
    {
        $content = $this->createValidDHCID();  // Properly formatted DHCID
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
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);

        // Check for warnings
        $this->assertTrue($result->hasWarnings());
        $this->assertNotEmpty($result->getWarnings());

        // Check for placement warning
        $placementWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'same name as the A or AAAA') !== false) {
                $placementWarningFound = true;
                break;
            }
        }
        $this->assertTrue($placementWarningFound, 'Should include a warning about proper record placement');
    }

    public function testValidateWithInvalidBase64Data()
    {
        $content = 'A5LT5$bpdy3T06UHGGg7yaQ==';  // Invalid base64 ($ character)
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('valid base64 characters', $result->getFirstError());
    }

    public function testValidateWithInvalidBase64Padding()
    {
        $content = 'A5LT5bpdy3T06UHGGg7ya=Q=';  // Invalid padding (= in the wrong place)
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('padding', $result->getFirstError());
    }

    public function testValidateWithTooShortDHCID()
    {
        // This is valid base64 but decodes to only 2 bytes
        $content = 'AAE=';  // Just the identifier type (0x0001)
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('too short', $result->getFirstError());
    }

    public function testValidateWithInvalidIdentifierType()
    {
        // Create a DHCID with an invalid identifier type (0x1234)
        $binary = pack('n', 0x1234) . chr(1) . random_bytes(32);
        $content = base64_encode($binary);

        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid()); // It's still valid, but should have a warning
        // Check for identifier type warning
        $this->assertTrue($result->hasWarnings());
        $identifierWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'identifier type') !== false && strpos($warning, '0x1234') !== false) {
                $identifierWarningFound = true;
                break;
            }
        }
        $this->assertTrue($identifierWarningFound, 'Should warn about non-standard identifier type');
    }

    public function testValidateWithInvalidDigestType()
    {
        // Create a DHCID with a valid identifier type but invalid digest type (2 instead of 1)
        $binary = pack('n', 0x0001) . chr(2) . random_bytes(32);
        $content = base64_encode($binary);

        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid()); // It's still valid, but should have a warning
        // Check for digest type warning
        $this->assertTrue($result->hasWarnings());
        $digestWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'digest type') !== false && strpos($warning, '2') !== false) {
                $digestWarningFound = true;
                break;
            }
        }
        $this->assertTrue($digestWarningFound, 'Should warn about non-standard digest type');
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'A5LT5bpdy3T06UHGGg7yaQ==';
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
        $content = 'A5LT5bpdy3T06UHGGg7yaQ==';
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
        $content = 'A5LT5bpdy3T06UHGGg7yaQ==';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals(86400, $data['ttl']);
    }
}
