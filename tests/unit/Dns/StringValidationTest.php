<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Domain\Service\DnsValidation\StringValidator;

/**
 * Tests for string formatting and validation
 */
class StringValidationTest extends BaseDnsTest
{
    public function testIsValidPrintable()
    {
        // Valid printable strings
        $this->assertTrue(StringValidator::isValidPrintable("Simple text"));
        $this->assertTrue(StringValidator::isValidPrintable("Text with numbers 123"));
        $this->assertTrue(StringValidator::isValidPrintable("Text with symbols !@#$%^&*()_+=-[]{};:'\",./<>?"));
        $this->assertTrue(StringValidator::isValidPrintable(" Text with spaces "));

        // Test would fail with non-printable characters, but we can't easily represent those in code
        // So we'll just skip that kind of test
    }

    public function testHasHtmlTags()
    {
        // Strings with HTML tags (should return true, indicating invalid for DNS records)
        $this->assertTrue(StringValidator::hasHtmlTags("<script>alert('XSS');</script>"));
        $this->assertTrue(StringValidator::hasHtmlTags("<b>Bold text</b>"));
        $this->assertTrue(StringValidator::hasHtmlTags("Text with <br> tag"));
        $this->assertTrue(StringValidator::hasHtmlTags("Text with <> brackets"));

        // Strings without HTML tags (should return false, indicating valid for DNS records)
        $this->assertFalse(StringValidator::hasHtmlTags("Plain text"));
        $this->assertFalse(StringValidator::hasHtmlTags("Text with symbols !@#$%^&*()_+=-[]{};:'\",./?|`~"));
    }

    public function testHasQuotesAround()
    {
        // Valid strings with quotes around
        $this->assertTrue(Dns::has_quotes_around('"This is quoted text"'));
        $this->assertTrue(Dns::has_quotes_around('"v=spf1 include:example.com ~all"'));

        // Empty string should pass
        $this->assertTrue(Dns::has_quotes_around(''));

        // Invalid strings without quotes or with incomplete quotes
        $this->assertFalse(Dns::has_quotes_around('This is not quoted text'));
        $this->assertFalse(Dns::has_quotes_around('"This is only start quoted'));
        $this->assertFalse(Dns::has_quotes_around('This is only end quoted"'));
    }

    public function testIsProperlyQuoted()
    {
        $this->assertTrue(StringValidator::isProperlyQuoted('"This is a \"properly\" quoted string."'));
        $this->assertTrue(StringValidator::isProperlyQuoted('This string has no quotes'));
        $this->assertTrue(StringValidator::isProperlyQuoted(''));
        $this->assertTrue(StringValidator::isProperlyQuoted('"This is \"properly\" quoted"'));

        // Already covered by existing tests, but adding a few more cases
        $this->assertTrue(StringValidator::isProperlyQuoted('"This is a properly quoted string with escaped \"quotes\" inside."'));
        $this->assertTrue(StringValidator::isProperlyQuoted('Simple string without quotes'));

        // Invalid quotes - unescaped quotes inside quoted text
        $this->assertFalse(StringValidator::isProperlyQuoted('"This has unescaped "quotes" inside."'));
    }
}
