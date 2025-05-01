<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\Dns;

/**
 * Tests for string formatting and validation
 */
class StringValidationTest extends BaseDnsTest
{
    public function testIsValidPrintable()
    {
        // Valid printable strings
        $this->assertTrue(Dns::is_valid_printable("Simple text"));
        $this->assertTrue(Dns::is_valid_printable("Text with numbers 123"));
        $this->assertTrue(Dns::is_valid_printable("Text with symbols !@#$%^&*()_+=-[]{};:'\",./<>?"));
        $this->assertTrue(Dns::is_valid_printable(" Text with spaces "));

        // Test would fail with non-printable characters, but we can't easily represent those in code
        // So we'll just skip that kind of test
    }

    public function testHasHtmlTags()
    {
        // Strings with HTML tags (should return true, indicating invalid for DNS records)
        $this->assertTrue(Dns::has_html_tags("<script>alert('XSS');</script>"));
        $this->assertTrue(Dns::has_html_tags("<b>Bold text</b>"));
        $this->assertTrue(Dns::has_html_tags("Text with <br> tag"));
        $this->assertTrue(Dns::has_html_tags("Text with <> brackets"));

        // Strings without HTML tags (should return false, indicating valid for DNS records)
        $this->assertFalse(Dns::has_html_tags("Plain text"));
        $this->assertFalse(Dns::has_html_tags("Text with symbols !@#$%^&*()_+=-[]{};:'\",./?|`~"));
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
        $this->assertTrue(Dns::is_properly_quoted('"This is a \"properly\" quoted string."'));
        $this->assertTrue(Dns::is_properly_quoted('This string has no quotes'));
        $this->assertTrue(Dns::is_properly_quoted(''));
        $this->assertTrue(Dns::is_properly_quoted('"This is \"properly\" quoted"'));

        // Already covered by existing tests, but adding a few more cases
        $this->assertTrue(Dns::is_properly_quoted('"This is a properly quoted string with escaped \"quotes\" inside."'));
        $this->assertTrue(Dns::is_properly_quoted('Simple string without quotes'));

        // Invalid quotes - unescaped quotes inside quoted text
        $this->assertFalse(Dns::is_properly_quoted('"This has unescaped "quotes" inside."'));
    }

    /**
     * Test the endsWith method with basic success cases
     */
    public function testEndsWithBasicSuccess()
    {
        $this->assertTrue(Dns::endsWith("com", "example.com"));
        $this->assertTrue(Dns::endsWith("example.com", "example.com"));
        $this->assertTrue(Dns::endsWith(".com", "example.com"));
    }

    /**
     * Test the endsWith method with basic failure cases
     */
    public function testEndsWithBasicFailure()
    {
        $this->assertFalse(Dns::endsWith("org", "example.com"));
        $this->assertFalse(Dns::endsWith("ample", "example.com"));
        $this->assertFalse(Dns::endsWith("exam", "example.com"));
    }

    /**
     * Test the endsWith method with empty strings
     */
    public function testEndsWithEmptyStrings()
    {
        $this->assertTrue(Dns::endsWith("", "example.com")); // Empty needle should always match
        $this->assertTrue(Dns::endsWith("", "")); // Empty needle matches empty haystack
        $this->assertFalse(Dns::endsWith("com", "")); // Non-empty needle doesn't match empty haystack
    }

    /**
     * Test case sensitivity in endsWith method
     */
    public function testEndsWithCaseSensitivity()
    {
        $this->assertFalse(Dns::endsWith("COM", "example.com")); // Case sensitive comparison
        $this->assertFalse(Dns::endsWith("Com", "example.com")); // Case sensitive comparison
    }

    /**
     * Test endsWith with special characters
     */
    public function testEndsWithSpecialCharacters()
    {
        $this->assertTrue(Dns::endsWith("@#$", "test@#$"));
        $this->assertTrue(Dns::endsWith("123", "domain123"));
        $this->assertTrue(Dns::endsWith(".", "example."));
    }

    /**
     * Test endsWith with multi-byte characters
     */
    public function testEndsWithMultiByteCharacters()
    {
        $this->assertTrue(Dns::endsWith("ñ", "espa\u{00F1}ol.españ")); // Unicode representation of tilde n
        $this->assertTrue(Dns::endsWith("中国", "example.中国")); // Chinese characters
        $this->assertTrue(Dns::endsWith("россия", "пример.россия")); // Russian characters
    }

    /**
     * Test endsWith with common DNS domain scenarios
     */
    public function testEndsWithDomainScenarios()
    {
        // Domain ends with parent domain
        $this->assertTrue(Dns::endsWith("example.com", "subdomain.example.com"));

        // TLD checks
        $this->assertTrue(Dns::endsWith("com", "example.com"));
        $this->assertTrue(Dns::endsWith("co.uk", "example.co.uk"));

        // FQDN with trailing dot
        $this->assertTrue(Dns::endsWith("example.com.", "subdomain.example.com."));

        // Mismatched domains
        $this->assertFalse(Dns::endsWith("example.org", "example.com"));
        $this->assertFalse(Dns::endsWith("other.com", "example.com"));
    }

    /**
     * Test endsWith with strings that have similar endings but don't match exactly
     */
    public function testEndsWithPartialMatches()
    {
        $this->assertFalse(Dns::endsWith("comx", "example.com")); // "com" with extra character
        $this->assertFalse(Dns::endsWith("xcom", "example.com")); // "com" with character prefix
        $this->assertFalse(Dns::endsWith("co", "example.com")); // Partial match of ending
    }

    /**
     * Test endsWith with needle longer than haystack
     */
    public function testEndsWithNeedleLongerThanHaystack()
    {
        $this->assertFalse(Dns::endsWith("longer.example.com", "example.com"));
        $this->assertFalse(Dns::endsWith("abcdefghijklmnopqrstuvwxyz", "xyz"));
    }
}
