<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsIdnService;

/**
 * Tests for DNS IDN (Internationalized Domain Name) conversion service
 *
 * Covers fix(dns): validate empty strings in IDN conversion
 */
class DnsIdnServiceTest extends TestCase
{
    /**
     * Test toUtf8 returns empty string for empty input
     */
    public function testToUtf8ReturnsEmptyStringForEmptyInput(): void
    {
        $result = DnsIdnService::toUtf8('');
        $this->assertSame('', $result);
    }

    /**
     * Test toPunycode returns empty string for empty input
     */
    public function testToPunycodeReturnsEmptyStringForEmptyInput(): void
    {
        $result = DnsIdnService::toPunycode('');
        $this->assertSame('', $result);
    }

    /**
     * Test toUtf8 converts punycode to UTF-8
     */
    public function testToUtf8ConvertsPunycodeToUtf8(): void
    {
        // xn--nxasmq5b is punycode for "βόλος" (Greek)
        $result = DnsIdnService::toUtf8('xn--nxasmq5b.example.com');
        $this->assertStringContainsString('example.com', $result);
    }

    /**
     * Test toPunycode converts UTF-8 to punycode
     */
    public function testToPunycodeConvertsUtf8ToPunycode(): void
    {
        // German domain with umlaut
        $result = DnsIdnService::toPunycode('münchen.de');
        $this->assertStringStartsWith('xn--', $result);
        $this->assertStringContainsString('.de', $result);
    }

    /**
     * Test toUtf8 handles regular ASCII domain names
     */
    public function testToUtf8HandlesAsciiDomainNames(): void
    {
        $result = DnsIdnService::toUtf8('example.com');
        $this->assertSame('example.com', $result);
    }

    /**
     * Test toPunycode handles regular ASCII domain names
     */
    public function testToPunycodeHandlesAsciiDomainNames(): void
    {
        $result = DnsIdnService::toPunycode('example.com');
        $this->assertSame('example.com', $result);
    }

    /**
     * Test toUtf8 handles subdomain with IDN
     */
    public function testToUtf8HandlesSubdomainWithIdn(): void
    {
        $result = DnsIdnService::toUtf8('www.xn--mnchen-3ya.de');
        $this->assertStringContainsString('.de', $result);
    }

    /**
     * Test toPunycode handles subdomain with Unicode
     */
    public function testToPunycodeHandlesSubdomainWithUnicode(): void
    {
        $result = DnsIdnService::toPunycode('www.münchen.de');
        $this->assertStringStartsWith('www.xn--', $result);
    }

    /**
     * Test round-trip conversion preserves domain
     */
    public function testRoundTripConversionPreservesDomain(): void
    {
        $originalUtf8 = 'münchen.de';

        // UTF-8 -> Punycode -> UTF-8
        $punycode = DnsIdnService::toPunycode($originalUtf8);
        $backToUtf8 = DnsIdnService::toUtf8($punycode);

        $this->assertSame($originalUtf8, $backToUtf8);
    }

    /**
     * Test Japanese domain conversion
     */
    public function testJapaneseDomainConversion(): void
    {
        // Test with a Japanese domain
        $utf8Domain = '日本語.jp';
        $punycode = DnsIdnService::toPunycode($utf8Domain);

        $this->assertStringStartsWith('xn--', $punycode);
        $this->assertStringContainsString('.jp', $punycode);
    }

    /**
     * Test Chinese domain conversion
     */
    public function testChineseDomainConversion(): void
    {
        // Test with a Chinese domain
        $utf8Domain = '中国.cn';
        $punycode = DnsIdnService::toPunycode($utf8Domain);

        $this->assertStringStartsWith('xn--', $punycode);
        $this->assertStringContainsString('.cn', $punycode);
    }

    /**
     * Test Arabic domain conversion
     */
    public function testArabicDomainConversion(): void
    {
        // Test with an Arabic domain
        $utf8Domain = 'مثال.مصر';
        $punycode = DnsIdnService::toPunycode($utf8Domain);

        $this->assertStringStartsWith('xn--', $punycode);
    }
}
