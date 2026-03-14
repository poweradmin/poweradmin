<?php

namespace Unit\Domain\Service;

use PHPUnit\Framework\Attributes\DataProvider;
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
     */
    #[DataProvider('mixedCaseAsciiDomainsProvider')]
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
     */
    #[DataProvider('mixedCaseIdnDomainsProvider')]
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
     */
    #[DataProvider('userInputDomainsProvider')]
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

    /**
     * Test isIdn detects punycode in non-first label
     */
    public function testIsIdnWithPunycodeInTld(): void
    {
        $this->assertTrue(DnsIdnService::isIdn('example.xn--fiqs8s'));
    }

    /**
     * Test isIdn detects punycode in subdomain
     */
    public function testIsIdnWithPunycodeInSubdomain(): void
    {
        $this->assertTrue(DnsIdnService::isIdn('xn--sub.example.com'));
    }

    /**
     * Test isIdn with multiple punycode labels
     */
    public function testIsIdnWithMultiplePunycodeLabels(): void
    {
        $this->assertTrue(DnsIdnService::isIdn('xn--e1afmkfd.xn--p1ai'));
    }

    /**
     * Test convertContentToPunycode with simple domain types
     */
    #[DataProvider('simpleDomainContentProvider')]
    public function testConvertContentToPunycodeSimpleTypes(string $type, string $content, string $expected): void
    {
        $result = DnsIdnService::convertContentToPunycode($type, $content);
        $this->assertEquals($expected, $result);
    }

    public static function simpleDomainContentProvider(): array
    {
        return [
            'CNAME with IDN' => ['CNAME', 'münchen.de.', 'xn--mnchen-3ya.de.'],
            'CNAME with ASCII' => ['CNAME', 'example.com.', 'example.com.'],
            'NS with IDN' => ['NS', 'ns1.münchen.de.', 'ns1.xn--mnchen-3ya.de.'],
            'PTR with IDN' => ['PTR', 'münchen.de.', 'xn--mnchen-3ya.de.'],
            'DNAME with IDN' => ['DNAME', 'münchen.de.', 'xn--mnchen-3ya.de.'],
            'ALIAS with IDN' => ['ALIAS', 'münchen.de.', 'xn--mnchen-3ya.de.'],
            'MX with IDN' => ['MX', 'mail.münchen.de.', 'mail.xn--mnchen-3ya.de.'],
            'MX with ASCII' => ['MX', 'mail.example.com.', 'mail.example.com.'],
        ];
    }

    /**
     * Test convertContentToPunycode with compound content types
     */
    #[DataProvider('compoundContentProvider')]
    public function testConvertContentToPunycodeCompoundTypes(string $type, string $content, string $expected): void
    {
        $result = DnsIdnService::convertContentToPunycode($type, $content);
        $this->assertEquals($expected, $result);
    }

    public static function compoundContentProvider(): array
    {
        return [
            'SRV with IDN target' => ['SRV', '0 5060 sip.münchen.de.', '0 5060 sip.xn--mnchen-3ya.de.'],
            'SRV with ASCII' => ['SRV', '0 5060 sip.example.com.', '0 5060 sip.example.com.'],
            'RP with IDN domains' => ['RP', 'admin.münchen.de. info.münchen.de.', 'admin.xn--mnchen-3ya.de. info.xn--mnchen-3ya.de.'],
            'NAPTR with IDN replacement' => ['NAPTR', '100 10 "u" "sip+E2U" "!^.*$!sip:info@münchen.de!" sip.münchen.de.', '100 10 "u" "sip+E2U" "!^.*$!sip:info@münchen.de!" sip.xn--mnchen-3ya.de.'],
        ];
    }

    /**
     * Test convertContentToPunycode with non-domain types returns content unchanged
     */
    public function testConvertContentToPunycodeNonDomainType(): void
    {
        $this->assertEquals('192.168.1.1', DnsIdnService::convertContentToPunycode('A', '192.168.1.1'));
        $this->assertEquals('::1', DnsIdnService::convertContentToPunycode('AAAA', '::1'));
        $this->assertEquals('v=spf1 +all', DnsIdnService::convertContentToPunycode('TXT', 'v=spf1 +all'));
    }

    /**
     * Test convertContentToPunycode with empty content
     */
    public function testConvertContentToPunycodeWithEmptyContent(): void
    {
        $this->assertEquals('', DnsIdnService::convertContentToPunycode('CNAME', ''));
    }

    /**
     * Test toPunycode preserves root label "."
     */
    public function testToPunycodePreservesRootLabel(): void
    {
        $this->assertEquals('.', DnsIdnService::toPunycode('.'));
    }

    /**
     * Test convertContentToPunycode preserves root label for SRV target
     */
    public function testConvertContentPreservesRootLabelForSrv(): void
    {
        $this->assertEquals('0 0 .', DnsIdnService::convertContentToPunycode('SRV', '0 0 .'));
    }

    /**
     * Test convertContentToPunycode preserves root label for NAPTR replacement
     */
    public function testConvertContentPreservesRootLabelForNaptr(): void
    {
        $result = DnsIdnService::convertContentToPunycode('NAPTR', '100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .');
        $this->assertEquals('100 10 "u" "sip+E2U" "!^.*$!sip:info@example.com!" .', $result);
    }

    /**
     * Test convertContentToPunycode preserves root label for RP
     */
    public function testConvertContentPreservesRootLabelForRp(): void
    {
        $this->assertEquals('admin.example.com. .', DnsIdnService::convertContentToPunycode('RP', 'admin.example.com. .'));
    }

    /**
     * Test convertContentToPunycode with assembled SRV content containing IDN
     */
    public function testConvertContentSrvWithAssembledIdnContent(): void
    {
        $this->assertEquals(
            '0 5060 sip.xn--mnchen-3ya.de.',
            DnsIdnService::convertContentToPunycode('SRV', '0 5060 sip.münchen.de.')
        );
    }

    /**
     * Test convertContentToPunycode with HTTPS/SVCB/LP record types
     */
    #[DataProvider('secondPartDomainContentProvider')]
    public function testConvertContentToPunycodeSecondPartTypes(string $type, string $content, string $expected): void
    {
        $result = DnsIdnService::convertContentToPunycode($type, $content);
        $this->assertEquals($expected, $result);
    }

    public static function secondPartDomainContentProvider(): array
    {
        return [
            'HTTPS with IDN target' => ['HTTPS', '1 münchen.de. alpn=h2', '1 xn--mnchen-3ya.de. alpn=h2'],
            'HTTPS with ASCII target' => ['HTTPS', '1 example.com. alpn=h2', '1 example.com. alpn=h2'],
            'HTTPS with root target' => ['HTTPS', '0 .', '0 .'],
            'SVCB with IDN target' => ['SVCB', '1 münchen.de. alpn=h3', '1 xn--mnchen-3ya.de. alpn=h3'],
            'LP with IDN fqdn' => ['LP', '10 münchen.de.', '10 xn--mnchen-3ya.de.'],
            'LP with ASCII fqdn' => ['LP', '10 example.com.', '10 example.com.'],
        ];
    }
}
