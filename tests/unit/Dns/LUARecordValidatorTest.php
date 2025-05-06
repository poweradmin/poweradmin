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

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\LUARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;

class LUARecordValidatorTest extends TestCase
{
    private LUARecordValidator $validator;
    private ConfigurationManager $configMock;
    private TTLValidator $ttlValidatorMock;
    private HostnameValidator $hostnameValidatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        // Create a mock hostname validator that will pass validation
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->method('validate')
            ->willReturn(ValidationResult::success(['hostname' => 'lua.example.com']));

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

        $this->validator = new LUARecordValidator($this->configMock);

        // Inject the mock hostname validator
        $reflectionProperty = new ReflectionProperty(LUARecordValidator::class, 'hostnameValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->hostnameValidatorMock);

        // Inject the mock TTL validator
        $reflectionProperty = new ReflectionProperty(LUARecordValidator::class, 'ttlValidator');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->validator, $this->ttlValidatorMock);
    }

    /**
     * Test validation with valid Lua script
     */
    public function testValidateWithValidLuaScript(): void
    {
        $validLuaScript = 'function luaExample(dname, ip) return "192.0.2.1" end';

        $result = $this->validator->validate(
            $validLuaScript,        // content
            'lua.example.com',      // name
            '',                     // prio (empty as not used for LUA)
            3600,                   // ttl
            86400                   // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $data = $result->getData();
        $this->assertEquals($validLuaScript, $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['prio']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        $result = $this->validator->validate(
            '',                      // empty content
            'lua.example.com',       // name
            '',                      // prio
            3600,                    // ttl
            86400                    // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('cannot be empty', $result->getFirstError());
    }

    /**
     * Test validation with invalid Lua content
     */
    public function testValidateWithInvalidLuaContent(): void
    {
        // Missing 'end' keyword
        $invalidLuaScript = 'function luaExample(dname, ip) return "192.0.2.1"';

        $result = $this->validator->validate(
            $invalidLuaScript,       // invalid content
            'lua.example.com',       // name
            '',                      // prio
            3600,                    // ttl
            86400                    // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('valid Lua function', $result->getFirstError());
    }

    /**
     * Test validation with another invalid Lua pattern
     */
    public function testValidateWithAnotherInvalidLuaPattern(): void
    {
        // Missing 'function' keyword
        $invalidLuaScript = 'local x = 5; return x end';

        $result = $this->validator->validate(
            $invalidLuaScript,       // invalid content
            'lua.example.com',       // name
            '',                      // prio
            3600,                    // ttl
            86400                    // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('valid Lua function', $result->getFirstError());
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        $validLuaScript = 'function luaExample(dname, ip) return "192.0.2.1" end';

        $result = $this->validator->validate(
            $validLuaScript,         // content
            'lua.example.com',       // name
            '',                      // prio
            -1,                      // invalid ttl
            86400                    // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid TTL value', $result->getFirstError());
    }

    /**
     * Test validation with default TTL
     */
    public function testValidateWithDefaultTtl(): void
    {
        $validLuaScript = 'function luaExample(dname, ip) return "192.0.2.1" end';

        $result = $this->validator->validate(
            $validLuaScript,         // content
            'lua.example.com',       // name
            '',                      // prio
            '',                      // empty ttl, should use default
            86400                    // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($validLuaScript, $data['content']);
        $this->assertEquals(86400, $data['ttl']);
        $this->assertEquals(0, $data['prio']);
    }

    /**
     * Test validation with invalid priority
     */
    public function testValidateWithInvalidPriority(): void
    {
        $validLuaScript = 'function luaExample(dname, ip) return "192.0.2.1" end';

        $result = $this->validator->validate(
            $validLuaScript,         // content
            'lua.example.com',       // name
            10,                      // non-zero prio
            3600,                    // ttl
            86400                    // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Priority field', $result->getFirstError());
    }

    /**
     * Test with invalid printable characters
     */
    public function testValidateWithInvalidPrintableCharacters(): void
    {
        // Using a string with non-printable control characters
        $invalidLuaScript = "function luaExample(dname, ip)\n\x01return \"192.0.2.1\"\nend";

        $result = $this->validator->validate(
            $invalidLuaScript,       // invalid content with control char
            'lua.example.com',       // name
            '',                      // prio
            3600,                    // ttl
            86400                    // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid characters', $result->getFirstError());
    }
}
