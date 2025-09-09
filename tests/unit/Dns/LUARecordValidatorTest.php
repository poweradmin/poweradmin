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
        $this->assertEquals($validLuaScript, $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['prio']);

        // Verify warnings are included
        $this->assertTrue($result->hasWarnings());
        $this->assertIsArray($result->getWarnings());
        $this->assertGreaterThan(0, count($result->getWarnings()));
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
        $this->assertStringContainsString('implicit return mode', $result->getFirstError());
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
        $this->assertStringContainsString('implicit return mode', $result->getFirstError());
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

    /**
     * Test validation with explicit return mode
     */
    public function testValidateWithExplicitReturnMode(): void
    {
        $luaScript = ';if(continent("EU")) then return "192.0.2.1" else return "198.51.100.1" end';

        $result = $this->validator->validate(
            $luaScript,             // explicit return mode (starts with ;)
            'lua.example.com',      // name
            '',                     // prio
            3600,                   // ttl
            86400                   // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Check for explicit return warning
        $foundExplicitReturnWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (stripos($warning, 'explicit return mode') !== false) {
                $foundExplicitReturnWarning = true;
                break;
            }
        }
        $this->assertTrue($foundExplicitReturnWarning, 'Warning about explicit return mode not found');
    }

    /**
     * Test validation with common PowerDNS function
     */
    public function testValidateWithCommonPowerDNSFunction(): void
    {
        $luaScript = 'pickclosest({"192.0.2.1", "198.51.100.1"})';

        $result = $this->validator->validate(
            $luaScript,              // common PowerDNS function
            'lua.example.com',       // name
            '',                      // prio
            3600,                    // ttl
            86400                    // defaultTTL
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with mismatched parentheses
     */
    public function testValidateWithMismatchedParentheses(): void
    {
        $invalidLuaScript = 'pickclosest({"192.0.2.1", "198.51.100.1"';

        $result = $this->validator->validate(
            $invalidLuaScript,      // mismatched parentheses
            'lua.example.com',      // name
            '',                     // prio
            3600,                   // ttl
            86400                   // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('mismatched parentheses', $result->getFirstError());
    }

    /**
     * Test validation with mismatched braces
     */
    public function testValidateWithMismatchedBraces(): void
    {
        $invalidLuaScript = 'pickclosest({"192.0.2.1", "198.51.100.1")';

        $result = $this->validator->validate(
            $invalidLuaScript,      // mismatched braces
            'lua.example.com',      // name
            '',                     // prio
            3600,                   // ttl
            86400                   // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('mismatched braces', $result->getFirstError());
    }

    /**
     * Test validation with dangerous system functions
     */
    public function testValidateWithDangerousSystemFunctions(): void
    {
        $dangerousScript = 'function dangerous() os.execute("rm -rf /") return "192.0.2.1" end';

        $result = $this->validator->validate(
            $dangerousScript,       // contains os.execute
            'lua.example.com',      // name
            '',                     // prio
            3600,                   // ttl
            86400                   // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('dangerous system access', $result->getFirstError());
    }

    /**
     * Test validation with explicit return mode but no return statement
     */
    public function testValidateWithExplicitReturnModeNoReturn(): void
    {
        $invalidLuaScript = ';if(continent("EU")) then print("Europe") else print("Not Europe") end';

        $result = $this->validator->validate(
            $invalidLuaScript,      // explicit mode with no return
            'lua.example.com',      // name
            '',                     // prio
            3600,                   // ttl
            86400                   // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain at least one "return" statement', $result->getFirstError());
    }

    /**
     * Test validation with unbalanced quotes warning
     */
    public function testValidateWithUnbalancedQuotes(): void
    {
        $unbalancedQuotesScript = 'function test() return "unbalanced quote end';

        $result = $this->validator->validate(
            $unbalancedQuotesScript, // unbalanced quotes
            'lua.example.com',       // name
            '',                      // prio
            3600,                    // ttl
            86400                    // defaultTTL
        );

        $this->assertTrue($result->isValid()); // Still valid but has warnings
        $data = $result->getData();

        // Check for unbalanced quotes warning
        $foundUnbalancedQuotesWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (stripos($warning, 'unbalanced quotes') !== false) {
                $foundUnbalancedQuotesWarning = true;
                break;
            }
        }
        $this->assertTrue($foundUnbalancedQuotesWarning, 'Warning about unbalanced quotes not found');
    }

    /**
     * Test validation with ifportup function (PowerDNS documentation example)
     */
    public function testValidateWithIfPortUpFunction(): void
    {
        $luaScript = 'ifportup(443, {\'192.0.2.1\', \'192.0.2.2\'})';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($luaScript, $data['content']);
    }

    /**
     * Test validation with ifportup function with nested arrays
     */
    public function testValidateWithIfPortUpNestedArrays(): void
    {
        $luaScript = 'ifportup(443, {{\'192.0.2.1\', \'192.0.2.2\'}, {\'192.0.3.1\'}})';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with ifportup and selector option
     */
    public function testValidateWithIfPortUpSelector(): void
    {
        $luaScript = 'ifportup(443, {\'192.0.2.1\', \'192.0.2.2\', \'198.51.100.1\'}, {selector=\'pickclosest\'})';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with ifurlup function
     */
    public function testValidateWithIfUrlUpFunction(): void
    {
        $luaScript = 'ifurlup(\'https://www.example.com/\', {{\'192.0.2.1\', \'192.0.2.2\'}, {\'198.51.100.1\'}}, {stringmatch=\'Example\'})';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with view function
     */
    public function testValidateWithViewFunction(): void
    {
        $luaScript = 'view({{\'192.168.0.0/16\'}, {\'192.168.1.54\'}}, {{\'0.0.0.0/0\'}, {\'192.0.2.1\'}})';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with pickwhashed function
     */
    public function testValidateWithPickWHashedFunction(): void
    {
        $luaScript = 'pickwhashed({{15, "192.0.2.1"}, {100, "198.51.100.5"}})';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with pickchashed function
     */
    public function testValidateWithPickCHashedFunction(): void
    {
        $luaScript = 'pickchashed({{15, "192.0.2.1"}, {100, "198.51.100.5"}})';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with picknamehashed function
     */
    public function testValidateWithPickNameHashedFunction(): void
    {
        $luaScript = 'picknamehashed({{15, "192.0.2.1"}, {100, "198.51.100.5"}})';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with createReverse function
     */
    public function testValidateWithCreateReverseFunction(): void
    {
        $luaScript = 'createReverse(\'%1%.%2%.%3%.%4%.static.example.com\')';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with dblookup function (issue #770 case)
     */
    public function testValidateWithDbLookupFunction(): void
    {
        $luaScript = 'dblookup(\'www1.example.com\', pdns.A)';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with dblookup in ifurlup context
     */
    public function testValidateWithDbLookupInIfUrlUp(): void
    {
        $luaScript = 'ifurlup(\'https://www.example.com/\', {dblookup(\'www1.example.com\', pdns.A), dblookup(\'www2.example.com\', pdns.A)})';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with explicit return mode using qname
     */
    public function testValidateWithExplicitReturnQName(): void
    {
        $luaScript = ';return \'Got a TXT query for \' .. qname:toString() .. \'; First label is: \' .. qname:getRawLabels()[1]';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with continent function in explicit return mode
     */
    public function testValidateWithContinentFunction(): void
    {
        $luaScript = ';if continent(\'EU\') then return {\'192.0.2.1\',\'192.0.2.2\',\'198.51.100.1\'} else return \'192.0.2.2\' end';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with country function in explicit return mode
     */
    public function testValidateWithCountryFunction(): void
    {
        $luaScript = ';if country(\'US\') then return {\'192.0.2.1\',\'192.0.2.2\',\'198.51.100.1\'} else return \'192.0.2.2\' end';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
    }

    /**
     * Test validation with record type prefix followed by dblookup (issue #770 format)
     */
    public function testValidateWithRecordTypePrefixAndDbLookup(): void
    {
        $luaScript = 'AAAA "dblookup(\'testmachine.dyn6.test.lan\', pdns.AAAA)"';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($luaScript, $data['content']);
    }

    /**
     * Test validation with A record type prefix
     */
    public function testValidateWithARecordTypePrefix(): void
    {
        $luaScript = 'A "dblookup(\'testmachine.dyn4.test.lan\', pdns.A)"';

        $result = $this->validator->validate(
            $luaScript,
            'lua.example.com',
            '',
            3600,
            86400
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($luaScript, $data['content']);
    }
}
