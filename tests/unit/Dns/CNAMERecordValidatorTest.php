<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\SqliteDnsBackendTestCase;

/**
 * Tests for the CNAMERecordValidator
 *
 * Conflict lookups run against a SqlDnsBackendProvider over in-memory sqlite,
 * seeded per test; an unseeded provider means "no conflicting records".
 */
class CNAMERecordValidatorTest extends SqliteDnsBackendTestCase
{

    private CNAMERecordValidator $validator;
    private MockObject&ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                // Mock DNS validation settings to their default values
                if ($section === 'dns') {
                    switch ($key) {
                        case 'top_level_tld_check':
                            return false;
                        case 'strict_tld_check':
                            return false;
                        default:
                            return 'example.com';
                    }
                }
                return 'example.com';
            });

        $this->validator = new CNAMERecordValidator($this->configMock, $this->sqliteBackendProvider());
    }

    public function testValidateWithValidData()
    {
        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $rid = 0;
        $zone = 'example.com';

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL, $rid, $zone);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithConflictingRecord()
    {
        // An A record already owns the name, so a CNAME cannot be added beside it
        $this->validator = new CNAMERecordValidator($this->configMock, $this->sqliteBackendProvider([
            [10, 1, 'alias.example.com', 'A', '192.0.2.1'],
        ]));

        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('already exists a record', $result->getFirstError());
    }

    public function testValidateWithInvalidSourceHostname()
    {
        $content = 'target.example.com';
        $name = '-invalid-hostname.example.com'; // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('A hostname can not start or end with a dash', $result->getFirstError());
    }

    public function testValidateWithInvalidTargetHostname()
    {
        $content = '-invalid-target.example.com'; // Invalid target hostname
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('A hostname can not start or end with a dash', $result->getFirstError());
    }

    public function testValidateWithEmptyCname()
    {
        $content = 'target.example.com';
        $name = 'example.com'; // Same as zone = empty CNAME
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;
        $rid = 0;
        $zone = 'example.com';

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL, $rid, $zone);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Empty CNAME records', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    public function testValidateWithInvalidPriority()
    {
        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 10; // Invalid priority for CNAME record
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid value for priority field', $result->getFirstError());
    }

    public function testValidateWithEmptyPriority()
    {
        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = ''; // Empty priority should default to 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'target.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidatePriority()
    {
        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validatePriority');
        $method->setAccessible(true);

        // With empty priority
        $result = $method->invoke($this->validator, '');
        $this->assertTrue($result->isValid());
        $this->assertEquals(0, $result->getData());

        // With zero priority
        $result = $method->invoke($this->validator, 0);
        $this->assertTrue($result->isValid());
        $this->assertEquals(0, $result->getData());

        // With invalid priority
        $result = $method->invoke($this->validator, 10);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid value for priority field', $result->getFirstError());
    }

    public function testValidateCnameUnique()
    {
        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateCnameUnique');
        $method->setAccessible(true);

        // Only a CNAME of the same name exists: unique as far as other types go
        $this->validator = new CNAMERecordValidator($this->configMock, $this->sqliteBackendProvider([
            [10, 1, 'unique.example.com', 'CNAME', 'target.example.com'],
            [11, 1, 'conflict.example.com', 'A', '192.0.2.1'],
        ]));

        $result = $method->invoke($this->validator, 'unique.example.com', 0);
        $this->assertTrue($result->isValid());

        // A non-CNAME record of the same name (matched case-insensitively) conflicts
        $result = $method->invoke($this->validator, 'CONFLICT.example.com', 0);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('already exists a record', $result->getFirstError());
    }

    public function testValidateCnameName()
    {
        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateCnameName');
        $method->setAccessible(true);

        // A TXT pointing at the name is harmless; an MX target cannot become a CNAME
        $this->validator = new CNAMERecordValidator($this->configMock, $this->sqliteBackendProvider([
            [10, 1, 'note.example.com', 'TXT', 'valid.example.com'],
            [11, 1, 'example.com', 'MX', 'invalid.example.com'],
        ]));

        $result = $method->invoke($this->validator, 'valid.example.com');
        $this->assertTrue($result->isValid());

        $result = $method->invoke($this->validator, 'invalid.example.com');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Did you assign an MX or NS record', $result->getFirstError());
    }

    public function testValidateNotEmptyCnameRR()
    {
        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateNotEmptyCnameRR');
        $method->setAccessible(true);

        // Valid case (name different from zone)
        $result = $method->invoke($this->validator, 'alias.example.com', 'example.com');
        $this->assertTrue($result->isValid());

        // Invalid case (empty CNAME - name equals zone)
        $result = $method->invoke($this->validator, 'example.com', 'example.com');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Empty CNAME records', $result->getFirstError());
    }

    public function testValidateAllowsSingleLabelTargetWhenTopLevelTldCheckIsOff()
    {
        // Mirrors HostnameValidator, which permits single-label names with this
        // setting off.
        $result = $this->validator->validate('www', 'alias.example.com', 0, 3600, 86400);

        $this->assertTrue($result->isValid());
    }

    public function testValidateRejectsSingleLabelTargetWhenTopLevelTldCheckIsOn()
    {
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key, $default = null) {
                if ($section === 'dns') {
                    switch ($key) {
                        case 'top_level_tld_check':
                            return true;
                        case 'strict_tld_check':
                            return false;
                        case 'custom_tlds':
                            return [];
                        default:
                            return 'example.com';
                    }
                }
                return $default ?? 'example.com';
            });

        $validator = new CNAMERecordValidator($configMock, $this->sqliteBackendProvider());

        $result = $validator->validate('www', 'alias.example.com', 0, 3600, 86400);

        $this->assertFalse($result->isValid());
        // Rejected by HostnameValidator, which owns this policy for both record
        // names and CNAME targets.
        $this->assertStringContainsString('Single-label hostnames are not allowed', $result->getFirstError());
    }

    public function testValidateWithInvalidTldTarget()
    {
        $content = 'www.123'; // Invalid: TLD should be letters only
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('CNAME target must be a fully qualified domain name', $result->getFirstError());
    }

    public function testValidateWithValidFqdnTarget()
    {
        $content = 'www.example.com'; // Valid FQDN
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithRootTargetFqdn()
    {
        $content = '.'; // Valid: root zone
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithCustomTldRejectedByDefault()
    {
        // Default config has empty custom_tlds, so dn42 should be rejected
        $content = 'ns1.example.dn42'; // Custom TLD with numbers
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('CNAME target must be a fully qualified domain name', $result->getFirstError());
    }

    public function testValidateWithCustomTldWhitelisted()
    {
        // Create a new config mock that returns custom_tlds
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key, $default = null) {
                if ($section === 'dns') {
                    switch ($key) {
                        case 'custom_tlds':
                            return ['dn42', 'home', 'internal'];
                        case 'top_level_tld_check':
                        case 'strict_tld_check':
                            return false;
                        default:
                            return 'example.com';
                    }
                }
                return $default ?? 'example.com';
            });

        $validator = new CNAMERecordValidator($configMock, $this->sqliteBackendProvider());

        $content = 'ns1.example.dn42'; // Custom TLD in whitelist
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithCustomTldCaseInsensitive()
    {
        // Create a config mock with lowercase custom_tlds
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key, $default = null) {
                if ($section === 'dns') {
                    switch ($key) {
                        case 'custom_tlds':
                            return ['dn42']; // lowercase in config
                        case 'top_level_tld_check':
                        case 'strict_tld_check':
                            return false;
                        default:
                            return 'example.com';
                    }
                }
                return $default ?? 'example.com';
            });

        $validator = new CNAMERecordValidator($configMock, $this->sqliteBackendProvider());

        // Test with uppercase TLD in target
        $content = 'ns1.example.DN42'; // uppercase TLD
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
    }

    public function testValidateStandardTldWithCustomWhitelistConfigured()
    {
        // Create a config mock with custom_tlds
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key, $default = null) {
                if ($section === 'dns') {
                    switch ($key) {
                        case 'custom_tlds':
                            return ['dn42', 'home'];
                        case 'top_level_tld_check':
                        case 'strict_tld_check':
                            return false;
                        default:
                            return 'example.com';
                    }
                }
                return $default ?? 'example.com';
            });

        $validator = new CNAMERecordValidator($configMock, $this->sqliteBackendProvider());

        // Standard TLD should still work
        $content = 'www.example.com';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithEmptyCustomTldWhitelist()
    {
        // Create a config mock that explicitly returns empty array
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key, $default = null) {
                if ($section === 'dns') {
                    switch ($key) {
                        case 'custom_tlds':
                            return []; // Explicitly empty
                        case 'top_level_tld_check':
                        case 'strict_tld_check':
                            return false;
                        default:
                            return 'example.com';
                    }
                }
                return $default ?? 'example.com';
            });

        $validator = new CNAMERecordValidator($configMock, $this->sqliteBackendProvider());

        // Custom TLD should be rejected when whitelist is empty
        $content = 'ns1.example.dn42';
        $name = 'alias.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('CNAME target must be a fully qualified domain name', $result->getFirstError());
    }

    /**
     * Editing a CNAME from the GUI passes the record ID as a numeric string
     * (it comes from $_POST). The duplicate check must still exclude the row
     * being edited - regression test for issue #1202.
     */
    public function testValidateCnameExistenceWithStringRidAppliesIdFilter()
    {
        // The only CNAME with this name is the row being edited
        $validator = new CNAMERecordValidator($this->configMock, $this->sqliteBackendProvider([
            [123, 1, 'alias.example.com', 'CNAME', 'target.example.com'],
        ]));

        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateCnameExistence');
        $method->setAccessible(true);

        $result = $method->invoke($validator, 'alias.example.com', '123');
        $this->assertTrue($result->isValid());

        $result = $method->invoke($validator, 'alias.example.com', '124');
        $this->assertFalse($result->isValid());
    }

    public function testValidateCnameUniqueWithStringRidAppliesIdFilter()
    {
        $validator = new CNAMERecordValidator($this->configMock, $this->sqliteBackendProvider([
            [123, 1, 'alias.example.com', 'A', '192.0.2.1'],
        ]));

        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateCnameUnique');
        $method->setAccessible(true);

        $result = $method->invoke($validator, 'alias.example.com', '123');
        $this->assertTrue($result->isValid());

        $result = $method->invoke($validator, 'alias.example.com', '124');
        $this->assertFalse($result->isValid());
    }

    public function testValidateCnameExistenceWithNewRecordSentinelSkipsIdFilter()
    {
        $validator = new CNAMERecordValidator($this->configMock, $this->sqliteBackendProvider([
            [123, 1, 'alias.example.com', 'CNAME', 'target.example.com'],
        ]));

        $reflection = new \ReflectionClass(CNAMERecordValidator::class);
        $method = $reflection->getMethod('validateCnameExistence');
        $method->setAccessible(true);

        // No row is excluded for a new record, so any existing CNAME conflicts
        $result = $method->invoke($validator, 'alias.example.com', -1);
        $this->assertFalse($result->isValid());

        $result = $method->invoke($validator, 'other.example.com', -1);
        $this->assertTrue($result->isValid());
    }
}
