<?php

namespace Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsIdnService;

class DnsIdnServiceTest extends TestCase
{
    /**
     * Test toPunycode with empty string
     */
    public function testToPunycodeWithEmptyString(): void
    {
        $result = DnsIdnService::toPunycode('');
        $this->assertEquals('', $result);
    }

    /**
     * Test toPunycode with string '0' (valid domain label)
     */
    public function testToPunycodeWithZeroString(): void
    {
        $result = DnsIdnService::toPunycode('0');
        $this->assertEquals('0', $result);
    }

    /**
     * Test toPunycode with regular ASCII domain
     */
    public function testToPunycodeWithAsciiDomain(): void
    {
        $result = DnsIdnService::toPunycode('example.com');
        $this->assertEquals('example.com', $result);
    }

    /**
     * Test toPunycode with IDN domain
     */
    public function testToPunycodeWithIdnDomain(): void
    {
        $result = DnsIdnService::toPunycode('münchen.de');
        $this->assertEquals('xn--mnchen-3ya.de', $result);
    }

    /**
     * Test toPunycode with Cyrillic IDN
     */
    public function testToPunycodeWithCyrillicDomain(): void
    {
        $result = DnsIdnService::toPunycode('пример.рф');
        $this->assertEquals('xn--e1afmkfd.xn--p1ai', $result);
    }

    /**
     * Test toUtf8 with empty string
     */
    public function testToUtf8WithEmptyString(): void
    {
        $result = DnsIdnService::toUtf8('');
        $this->assertEquals('', $result);
    }

    /**
     * Test toUtf8 with string '0' (valid domain label)
     */
    public function testToUtf8WithZeroString(): void
    {
        $result = DnsIdnService::toUtf8('0');
        $this->assertEquals('0', $result);
    }

    /**
     * Test toUtf8 with regular ASCII domain
     */
    public function testToUtf8WithAsciiDomain(): void
    {
        $result = DnsIdnService::toUtf8('example.com');
        $this->assertEquals('example.com', $result);
    }

    /**
     * Test toUtf8 with Punycode domain
     */
    public function testToUtf8WithPunycodeDomain(): void
    {
        $result = DnsIdnService::toUtf8('xn--mnchen-3ya.de');
        $this->assertEquals('münchen.de', $result);
    }

    /**
     * Test isIdn with ASCII domain
     */
    public function testIsIdnWithAsciiDomain(): void
    {
        $result = DnsIdnService::isIdn('example.com');
        $this->assertFalse($result);
    }

    /**
     * Test isIdn with UTF-8 IDN (not in Punycode format)
     */
    public function testIsIdnWithUtf8Domain(): void
    {
        $result = DnsIdnService::isIdn('münchen.de');
        $this->assertFalse($result);
    }

    /**
     * Test isIdn with Punycode domain
     */
    public function testIsIdnWithPunycodeDomain(): void
    {
        $result = DnsIdnService::isIdn('xn--mnchen-3ya.de');
        $this->assertTrue($result);
    }

    /**
     * Test toPunycode lowercases ASCII domain with mixed case
     *
     * @dataProvider mixedCaseAsciiDomainsProvider
     */
    public function testToPunycodeLowercasesAsciiDomain(string $input, string $expected): void
    {
        $result = DnsIdnService::toPunycode($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for mixed case ASCII domain tests
     */
    public static function mixedCaseAsciiDomainsProvider(): array
    {
        return [
            'uppercase domain' => ['EXAMPLE.COM', 'example.com'],
            'mixed case domain' => ['Example.COM', 'example.com'],
            'mixed case with subdomain' => ['Www.Example.COM', 'www.example.com'],
            'all lowercase (unchanged)' => ['example.com', 'example.com'],
            'camelCase style' => ['MyDomain.Com', 'mydomain.com'],
        ];
    }

    /**
     * Test toPunycode lowercases IDN domain with mixed case
     *
     * @dataProvider mixedCaseIdnDomainsProvider
     */
    public function testToPunycodeLowercasesIdnDomain(string $input, string $expected): void
    {
        $result = DnsIdnService::toPunycode($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for mixed case IDN domain tests
     */
    public static function mixedCaseIdnDomainsProvider(): array
    {
        return [
            'IDN with uppercase TLD' => ['münchen.DE', 'xn--mnchen-3ya.de'],
            'IDN with mixed case' => ['München.De', 'xn--mnchen-3ya.de'],
            'Cyrillic with uppercase' => ['ПРИМЕР.РФ', 'xn--e1afmkfd.xn--p1ai'],
            'IDN uppercase umlaut' => ['MÜNCHEN.DE', 'xn--mnchen-3ya.de'],
        ];
    }

    /**
     * Test toPunycode with simulated user input (with whitespace)
     *
     * Note: toPunycode does NOT trim whitespace - caller must trim first
     *
     * @dataProvider userInputDomainsProvider
     */
    public function testToPunycodeWithUserInput(string $input, string $expected): void
    {
        // Simulate proper input handling: trim then convert
        $result = DnsIdnService::toPunycode(trim($input));
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for user input simulation tests
     */
    public static function userInputDomainsProvider(): array
    {
        return [
            'leading/trailing spaces with mixed case' => ['  Example.COM  ', 'example.com'],
            'tabs and spaces' => ["\t Example.COM \t", 'example.com'],
            'IDN with spaces' => ['  Ēxample.COM  ', 'xn--xample-o3a.com'],
            'newlines' => ["\nExample.COM\n", 'example.com'],
        ];
    }

    /**
     * Test that toPunycode without trim does NOT handle whitespace
     */
    public function testToPunycodeDoesNotTrimWhitespace(): void
    {
        // idn_to_ascii returns false for invalid input with spaces
        $result = DnsIdnService::toPunycode('  example.com  ');

        // Result should be false (converted to empty string) or contain the spaces
        // depending on PHP version - the key point is it doesn't work correctly
        $this->assertNotEquals('example.com', $result);
    }
}
