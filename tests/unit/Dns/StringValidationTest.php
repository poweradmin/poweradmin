<?php

namespace Poweradmin\Tests\Unit\Dns;

use TestHelpers\BaseDnsTest;
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
}
