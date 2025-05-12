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

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsValidation\NAPTRRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class NAPTRRecordValidatorTest extends BaseDnsTest
{
    private NAPTRRecordValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns') {
                    if ($key === 'top_level_tld_check') {
                        return false;
                    }
                    if ($key === 'strict_tld_check') {
                        return false;
                    }
                }
                return 'example.com'; // Default value for tests
            });
        $this->validator = new NAPTRRecordValidator($configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .';
        $name = 'test.example.com';
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
    }

    public function testValidateWithInvalidOrderValue()
    {
        $content = '65536 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Invalid order (out of range)
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('order must be a number between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithInvalidPreferenceValue()
    {
        $content = '100 -1 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Invalid preference (negative)
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('preference must be a number between 0 and 65535', $result->getFirstError());
    }

    public function testValidateWithInvalidFlagsFormat()
    {
        $content = '100 10 u "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Flags not quoted
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('flags must be a quoted string', $result->getFirstError());
    }

    public function testValidateWithInvalidFlagsValue()
    {
        $content = '100 10 "X" "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Invalid flag 'X'
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('flags must contain only A, P, S, or U', $result->getFirstError());
    }

    public function testValidateWithUnquotedServiceFormat()
    {
        $content = '100 10 "u" sip+E2U "!^.*$!sip:info@example.com!" .'; // Service not quoted
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('service must be a quoted string', $result->getFirstError());
    }

    public function testValidateWithUnquotedRegexpFormat()
    {
        $content = '100 10 "u" "sip+E2U" !^.*$!sip:info@example.com! .'; // Regexp not quoted
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('regexp must be a quoted string', $result->getFirstError());
    }

    public function testValidateWithInvalidReplacement()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" -invalid.example.com.'; // Invalid domain name
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('replacement must be either "." or a valid fully-qualified domain name', $result->getFirstError());
    }

    public function testValidateWithInvalidName()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .';
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
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .';
        $name = 'test.example.com';
        $prio = 0;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithMissingComponents()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!"'; // Missing replacement
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('must contain order, preference, flags, service, regexp, and replacement', $result->getFirstError());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .';
        $name = 'test.example.com';
        $prio = 0;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithEmptyFlags()
    {
        $content = '100 10 "" "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Empty flags is valid
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateWithMultipleFlags()
    {
        $content = '100 10 "SU" "sip+E2U" "!^.*$!sip:info@example.com!" .'; // Multiple terminal flags - should fail
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Terminal flags', $result->getFirstError());
    }

    public function testValidateWithInvalidServiceFormat()
    {
        $content = '100 10 "u" "sip-E2U" "!^.*$!sip:info@example.com!" .'; // Invalid service format (hyphen)
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('service must follow the format', $result->getFirstError());
    }

    public function testValidateWithValidENUMRecord()
    {
        $content = '100 10 "u" "E2U+sip" "!^.*$!sip:info@example.com!" .'; // Valid ENUM record format
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $this->assertNotEmpty($result->getWarnings());
        // At least one warning should mention ENUM
        $foundEnumWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'ENUM') !== false) {
                $foundEnumWarning = true;
                break;
            }
        }
        $this->assertTrue($foundEnumWarning);
    }

    public function testValidateWithInvalidRegexpFormat()
    {
        $content = '100 10 "u" "sip+E2U" "^.*$!sip:info@example.com!" .'; // Missing first delimiter
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('delimiter+pattern+delimiter+replacement+delimiter', $result->getFirstError());
    }

    public function testValidateWithExcessivelyLongRegexp()
    {
        // Create a very long regexp pattern (over 1000 chars)
        $longPattern = str_repeat('a', 1001);
        $content = '100 10 "u" "sip+E2U" "!' . $longPattern . '!replacement!" .';
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('too long', $result->getFirstError());
    }

    public function testValidateWithConflictingReplacementAndRegexp()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" example.com'; // Both regexp and replacement set
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('regexp must have "." as the replacement', $result->getFirstError());
    }

    public function testValidateWithParsedData()
    {
        $content = '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .';
        $name = 'test.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertArrayHasKey('parsed', $data);
        $this->assertTrue($data['parsed']);

        // Check for warnings - this validator always adds RFC warning
        $this->assertTrue($result->hasWarnings());
        $warningText = implode(' ', $result->getWarnings());
        $this->assertStringContainsString('RFC 3403', $warningText);
    }

    public function testValidateWithENUMDomain()
    {
        $content = '100 10 "u" "E2U+sip" "!^.*$!sip:info@example.com!" .';
        $name = '1.2.3.4.5.e164.arpa'; // ENUM domain
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $this->assertNotEmpty($result->getWarnings());
        // Should have ENUM domain warning
        $foundEnumDomainWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'ENUM domain') !== false) {
                $foundEnumDomainWarning = true;
                break;
            }
        }
        $this->assertTrue($foundEnumDomainWarning);
    }
}
