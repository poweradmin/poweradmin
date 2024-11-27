<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\DomainHelper;

class DomainHelperTest extends TestCase
{
    public function testGetDomains()
    {
        // Single domain without newline
        $input = "example.com";
        $expected = ['example.com'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Multiple domains with mixed newline characters
        $input = "example.com\nexample.org\r\nexample.net";
        $expected = ['example.com', 'example.org', 'example.net'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Multiple domains with empty lines
        $input = "example.com\r\nexample.org\r\n";
        $expected = ['example.com', 'example.org'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Multiple domains with empty lines at the beginning and end
        $input = "example.com\r\n\r\nexample.org\r\n";
        $expected = ['example.com', 'example.org'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Multiple domains with empty lines only
        $input = "\r\nexample.com\r\nexample.org\r\n";
        $expected = ['example.com', 'example.org'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Empty string
        $input = "";
        $expected = [];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Empty lines only
        $input = "\r\n\r\n";
        $expected = [];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Domains with leading and trailing spaces
        $input = " example.com \r\n example.org \r\n";
        $expected = ['example.com', 'example.org'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Domains with only spaces and newlines
        $input = " \r\n \r\n";
        $expected = [];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Domain with uppercase letters
        $input = "Example.COM";
        $expected = ['example.com'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Multiple domains with mixed case
        $input = "Example.COM\nExample.ORG\r\nExample.NET";
        $expected = ['example.com', 'example.org', 'example.net'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // IDN domain
        $input = "xn--d1acufc.xn--p1ai";
        $expected = ['xn--d1acufc.xn--p1ai'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Multiple IDN domains
        $input = "xn--d1acufc.xn--p1ai\nxn--80asehdb";
        $expected = ['xn--d1acufc.xn--p1ai', 'xn--80asehdb'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));

        // Mixed IDN and regular domains
        $input = "example.com\nxn--d1acufc.xn--p1ai";
        $expected = ['example.com', 'xn--d1acufc.xn--p1ai'];
        $this->assertEquals($expected, DomainHelper::getDomains($input));
    }
}
