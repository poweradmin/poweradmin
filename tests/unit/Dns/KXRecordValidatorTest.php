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
use Poweradmin\Domain\Service\DnsValidation\KXRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;

class KXRecordValidatorTest extends BaseDnsTest
{
    private KXRecordValidator $validator;

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
                return null;
            });
        $this->validator = new KXRecordValidator($configMock);
    }

    public function testValidKXRecord()
    {
        $content = 'kx.example.com';
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

        // Check for RFC 2230 security warnings
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $this->assertIsArray($warnings);
        $this->assertNotEmpty($warnings);

        // Check for DNSSEC requirement warning
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('DNSSEC', $warningText);
        $this->assertStringContainsString('RFC 2230', $warningText);

        // Check for specific additional section processing warning
        $this->assertStringContainsString('A/AAAA records', $warningText);
    }

    public function testInvalidKeyExchanger()
    {
        $content = '-invalid-.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getFirstError());
    }

    public function testInvalidDomainName()
    {
        $content = 'kx.example.com';
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
        $content = 'kx.example.com';
        $name = 'example.com';
        $prio = 65536; // Invalid priority (> 65535)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('preference field', $result->getFirstError());
    }

    public function testDefaultPriority()
    {
        $content = 'kx.example.com';
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
        $content = 'kx.example.com';
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
        $content = 'kx.example.com';
        $name = 'example.com';
        $prio = 10;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($defaultTTL, $data['ttl']);
    }

    public function testKXRecordWithSameExchangerAsOwner()
    {
        // Test case where key exchanger is the same as the owner name
        $content = 'example.com';  // Same as name
        $name = 'example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Check warnings - should only have the DNSSEC warnings, not the A/AAAA records warning
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('DNSSEC', $warningText);
        $this->assertStringContainsString('RFC 2230', $warningText);

        // Should not contain the additional records warning since exchanger is same as owner
        $this->assertStringNotContainsString('forward and reverse DNS records', $warningText);
    }

    public function testValidatePrivateMethods()
    {
        // Test validatePriority with reflection to access private method
        $reflectionMethod = new ReflectionMethod(KXRecordValidator::class, 'validatePriority');
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
        $this->assertStringContainsString('preference field', $result->getFirstError());

        // Invalid priority (non-numeric)
        $result = $reflectionMethod->invoke($this->validator, 'invalid');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('preference field', $result->getFirstError());
    }
}
