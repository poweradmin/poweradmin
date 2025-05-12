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
use Poweradmin\Domain\Service\DnsValidation\StringValidator;

/**
 * Tests for the StringValidator
 */
class StringValidatorTest extends TestCase
{
    public function testIsValidPrintable()
    {
        $this->assertTrue(StringValidator::isValidPrintable('abc123'));
        $this->assertTrue(StringValidator::isValidPrintable('example.com'));
        $this->assertTrue(StringValidator::isValidPrintable('Special@Chars!'));

        // Non-printable characters should fail
        $this->assertFalse(StringValidator::isValidPrintable("abc\x07def"));
    }

    public function testHasHtmlTags()
    {
        $this->assertTrue(StringValidator::hasHtmlTags('<html>'));
        $this->assertTrue(StringValidator::hasHtmlTags('abc<div>content</div>'));

        $this->assertFalse(StringValidator::hasHtmlTags('No html tags here'));
        $this->assertFalse(StringValidator::hasHtmlTags('email@example.com'));
    }

    public function testIsProperlyQuoted()
    {
        $this->assertTrue(StringValidator::isProperlyQuoted('"Simple quoted string"'));
        $this->assertTrue(StringValidator::isProperlyQuoted('"Quoted with \\"escaped\\" quotes"'));
        $this->assertTrue(StringValidator::isProperlyQuoted('Unquoted string'));

        $this->assertFalse(StringValidator::isProperlyQuoted('"Improperly quoted "string"'));
        $this->assertFalse(StringValidator::isProperlyQuoted('"Missing end quote'));
    }

    public function testHasQuotesAround()
    {
        $this->assertTrue(StringValidator::hasQuotesAround('"Quoted string"'));
        $this->assertTrue(StringValidator::hasQuotesAround('""')); // Empty quoted string
        $this->assertTrue(StringValidator::hasQuotesAround('')); // Empty string (special case)

        $this->assertFalse(StringValidator::hasQuotesAround('Unquoted string'));
        $this->assertFalse(StringValidator::hasQuotesAround('"Missing end quote'));
        $this->assertFalse(StringValidator::hasQuotesAround('Missing start quote"'));
    }

    public function testIsValidDomain()
    {
        // Valid domains
        $this->assertTrue(StringValidator::isValidDomain('example.com'));
        $this->assertTrue(StringValidator::isValidDomain('sub.example.com'));
        $this->assertTrue(StringValidator::isValidDomain('example-with-hyphens.com'));
        $this->assertTrue(StringValidator::isValidDomain('123numerical.com'));
        $this->assertTrue(StringValidator::isValidDomain('domain.io'));

        // Invalid domains
        $this->assertFalse(StringValidator::isValidDomain('invalid_underscore.com'));
        $this->assertFalse(StringValidator::isValidDomain('domain with spaces.com'));
        $this->assertFalse(StringValidator::isValidDomain('-startshyphen.com'));
        $this->assertFalse(StringValidator::isValidDomain('endshyphen-.com'));
        $this->assertFalse(StringValidator::isValidDomain('special$chars.com'));

        // Test label length (max 63 chars per label)
        $longLabel = str_repeat('a', 64) . '.com';
        $this->assertFalse(StringValidator::isValidDomain($longLabel));

        $validLongLabel = str_repeat('a', 63) . '.com';
        $this->assertTrue(StringValidator::isValidDomain($validLongLabel));

        // Test total domain length (max 253 chars)
        $longDomain = str_repeat('a', 250) . '.com';
        $this->assertFalse(StringValidator::isValidDomain($longDomain));
    }

    public function testValidateDomain()
    {
        // Valid domain
        $result = StringValidator::validateDomain('example.com');
        $this->assertTrue($result->isValid());
        $this->assertEquals('example.com', $result->getData());

        // Invalid domain
        $result = StringValidator::validateDomain('invalid_underscore.com');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid domain', $result->getFirstError());
    }

    public function testValidatePrintable()
    {
        // Valid printable string
        $result = StringValidator::validatePrintable('This is printable');
        $this->assertTrue($result->isValid());
        $this->assertEquals('This is printable', $result->getData());

        // Invalid printable string
        $result = StringValidator::validatePrintable("Contains\x07bell");
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid characters', $result->getFirstError());
    }

    public function testValidateNoHtmlTags()
    {
        // Valid string with no HTML tags
        $result = StringValidator::validateNoHtmlTags('No HTML here');
        $this->assertTrue($result->isValid());
        $this->assertEquals('No HTML here', $result->getData());

        // Invalid string with HTML tags
        $result = StringValidator::validateNoHtmlTags('<b>Bold text</b>');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('html tags', $result->getFirstError());
    }

    public function testValidateProperQuoting()
    {
        // Valid properly quoted string
        $result = StringValidator::validateProperQuoting('"This is properly quoted with \\"escaped quotes\\""');
        $this->assertTrue($result->isValid());

        // Invalid improperly quoted string
        $result = StringValidator::validateProperQuoting('"This has "unescaped" quotes"');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Backslashes must precede', $result->getFirstError());
    }

    public function testValidateQuotesAround()
    {
        // Valid string with quotes around
        $result = StringValidator::validateQuotesAround('"Quoted string"');
        $this->assertTrue($result->isValid());
        $this->assertEquals('"Quoted string"', $result->getData());

        // Invalid string without quotes around
        $result = StringValidator::validateQuotesAround('Not quoted');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Add quotes', $result->getFirstError());

        // Special case - empty string
        $result = StringValidator::validateQuotesAround('');
        $this->assertTrue($result->isValid());
        $this->assertEquals('', $result->getData());
    }
}
