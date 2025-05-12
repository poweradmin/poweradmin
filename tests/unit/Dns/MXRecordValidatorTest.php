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
use Poweradmin\Domain\Service\DnsValidation\MXRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;

class MXRecordValidatorTest extends BaseDnsTest
{
    private MXRecordValidator $validator;

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
                return 'example.com'; // Default value for tests from ValidationResultTest
            });
        $this->validator = new MXRecordValidator($configMock);
    }

    public function testValidateMXRecord()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals($prio, $data['prio']);
        $this->assertEquals($ttl, $data['ttl']);
    }

    public function testInvalidMailServerHostname()
    {
        $content = '-invalid-.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getFirstError());
        $this->assertStringContainsString('Invalid mail server hostname', $result->getFirstError());
    }

    public function testInvalidDomainName()
    {
        $content = 'mail.example.com';
        $name = '-invalid-.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getFirstError());
    }

    public function testInvalidPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 65536; // Invalid priority (> 65535)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid value for MX priority', $result->getFirstError());
    }

    public function testDefaultPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = ''; // Empty priority should default to 10
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(10, $data['prio']);
    }

    public function testInvalidTTL()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = -1; // Invalid negative TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getFirstError());
    }

    public function testDefaultTTL()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($defaultTTL, $data['ttl']);
    }

    public function testValidatePrivateMethods()
    {
        // Test validatePriority with reflection to access private method
        $reflectionMethod = new \ReflectionMethod(MXRecordValidator::class, 'validatePriority');
        $reflectionMethod->setAccessible(true);

        // Valid priority
        $result = $reflectionMethod->invoke($this->validator, 10);
        $this->assertTrue($result->isValid());
        $this->assertEquals(10, $result->getData());

        // Empty priority (should default to 10)
        $result = $reflectionMethod->invoke($this->validator, '');
        $this->assertTrue($result->isValid());
        $this->assertEquals(10, $result->getData());

        // Invalid priority (too large)
        $result = $reflectionMethod->invoke($this->validator, 65536);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());

        // Invalid priority (non-numeric)
        $result = $reflectionMethod->invoke($this->validator, 'invalid');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    // Additional tests from MXRecordValidatorResultTest

    public function testValidateWithNegativePriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = -1; // Invalid priority (negative)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid value for MX priority', $result->getFirstError());
    }

    public function testValidateWithNonNumericPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 'abc'; // Invalid priority (non-numeric)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('Invalid value for MX priority', $result->getFirstError());
    }

    public function testValidateWithLowPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 0; // Valid lowest priority according to RFC
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(0, $data['prio']);
    }

    public function testValidateWithHighPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 65535; // Valid highest priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(65535, $data['prio']);
    }

    public function testValidateWithStringTTL()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = '3600'; // String TTL should be parsed correctly
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithStringPriority()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = '20'; // String priority should be parsed correctly
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(20, $data['prio']);
        $this->assertIsInt($data['prio']);
    }

    // Tests for RFC 7505 null MX support

    public function testValidNullMXRecord()
    {
        $content = '.'; // Null MX target
        $name = 'example.com';
        $prio = 0; // Required for null MX
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('.', $data['content']);
        $this->assertEquals(0, $data['prio']);
        $this->assertTrue($data['is_null_mx']);
        $this->assertNotEmpty($result->getWarnings());
        $this->assertCount(2, $result->getWarnings());
    }

    public function testInvalidNullMXPriority()
    {
        $content = '.'; // Null MX target
        $name = 'example.com';
        $prio = 10; // Invalid for null MX - should be 0
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority 0', $result->getFirstError());
    }

    public function testWarningsForStandardMX()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertFalse($data['is_null_mx']);
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('CNAME', $result->getWarnings()[0]);
    }

    public function testHighPriorityWarning()
    {
        $content = 'mail.example.com';
        $name = 'example.com';
        $prio = 200; // Unusually high priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertNotEmpty($result->getWarnings());
        $this->assertCount(2, $result->getWarnings());
        $this->assertStringContainsString('Priority values above 100', $result->getWarnings()[1]);
    }
}
