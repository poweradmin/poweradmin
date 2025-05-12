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
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\OPENPGPKEYRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;

/**
 * Tests for the OPENPGPKEYRecordValidator
 */
class OPENPGPKEYRecordValidatorTest extends TestCase
{
    private OPENPGPKEYRecordValidator $validator;
    private ConfigurationManager $configMock;
    private HostnameValidator $hostnameValidatorMock;
    private TTLValidator $ttlValidatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        // Create a mock hostname validator that will pass validation
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->method('validate')
            ->willReturnCallback(function ($hostname, $wildcard) {
                if (strpos($hostname, 'invalid') !== false) {
                    return ValidationResult::failure('Invalid hostname');
                }
                return ValidationResult::success(['hostname' => $hostname]);
            });

        // Mock TTL validator
        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->ttlValidatorMock->method('validate')
            ->willReturnCallback(function ($ttl, $defaultTTL) {
                if ($ttl === -1) {
                    return ValidationResult::failure('Invalid TTL value');
                }
                if (empty($ttl)) {
                    return ValidationResult::success($defaultTTL);
                }
                return ValidationResult::success($ttl);
            });

        $this->validator = new OPENPGPKEYRecordValidator($this->configMock);

        // Inject the mock hostname validator
        $reflectionProperty = new ReflectionProperty(OPENPGPKEYRecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->hostnameValidatorMock);

        // Inject the mock TTL validator
        $reflectionProperty = new ReflectionProperty(OPENPGPKEYRecordValidator::class, 'ttlValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->ttlValidatorMock);
    }

    public function testValidateWithValidData()
    {
        // This is a sample base64 encoded data - not an actual PGP key
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'ab12cd._openpgpkey.example.com';
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
    }

    public function testValidateWithRegularHostname()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'pgp.example.com';  // Regular hostname without hash format
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($name, $data['name']);
    }

    public function testValidateWithEmptyContent()
    {
        $content = '';
        $name = 'ab12cd._openpgpkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('cannot be empty', $result->getFirstError());
    }

    public function testValidateWithInvalidBase64Content()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyi!FbFBKT4TzXcVB';  // Contains invalid ! character
        $name = 'ab12cd._openpgpkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('valid base64', $result->getFirstError());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'invalid.hostname';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid hostname', $result->getFirstError());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'ab12cd._openpgpkey.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid TTL value', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'ab12cd._openpgpkey.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithInvalidPriority()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'ab12cd._openpgpkey.example.com';
        $prio = 10;  // Non-zero priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field', $result->getFirstError());
    }

    public function testValidateWithInvalidPrintableCharactersInHostname()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = "ab12cd._openpgpkey.example.com\x01";  // With control character
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid characters in hostname', $result->getFirstError());
    }

    public function testValidateWithExactFormat56CharHash()
    {
        // This is a sample base64 encoded data - not an actual PGP key
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        // Valid 56-character hex hash followed by ._openpgpkey.example.com
        $name = 'c93f1e400f26708f98cb19d936620da35eec8f72e57f9eec01c1afd6._openpgpkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertTrue($result->hasWarnings());
        $this->assertIsArray($result->getWarnings());
        // Shouldn't have a warning about non-standard format
        $formatWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'does not follow the standard OPENPGPKEY format') !== false) {
                $formatWarningFound = true;
                break;
            }
        }
        $this->assertFalse($formatWarningFound);
    }

    public function testValidateWithInvalidHashFormat()
    {
        // This is a sample base64 encoded data - not an actual PGP key
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        // Invalid hash (wrong length and non-hex characters) before ._openpgpkey.example.com
        $name = 'abc-xyz._openpgpkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        // Should have a warning about non-standard format
        $formatWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'does not follow the standard OPENPGPKEY format') !== false) {
                $formatWarningFound = true;
                break;
            }
        }
        $this->assertTrue($formatWarningFound);
    }

    public function testValidateAndCheckForDnssecWarning()
    {
        $content = 'mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC/Ub7O1u120F2pvaG5AZXhhbXBsZS5jb20';
        $name = 'c93f1e400f26708f98cb19d936620da35eec8f72e57f9eec01c1afd6._openpgpkey.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertTrue($result->hasWarnings());
        $this->assertIsArray($result->getWarnings());

        // Should have a warning about DNSSEC requirement
        $dnssecWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'REQUIRE DNSSEC for any security benefit') !== false) {
                $dnssecWarningFound = true;
                break;
            }
        }
        $this->assertTrue($dnssecWarningFound);
    }
}
