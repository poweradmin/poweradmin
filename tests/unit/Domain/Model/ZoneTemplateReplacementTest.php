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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneTemplate;

/**
 * Test Zone Template Placeholder Replacement Functionality
 *
 * @package Poweradmin\Tests\Unit\Domain\Model
 * @covers \Poweradmin\Domain\Model\ZoneTemplate::replaceWithTemplatePlaceholders
 */
class ZoneTemplateReplacementTest extends TestCase
{
    public function testReplaceZoneInRecordName(): void
    {
        $domain = 'example.com';
        $record = [
            'name' => 'www.example.com',
            'content' => '192.168.1.1',
            'type' => 'A'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals('www.[ZONE]', $name);
        $this->assertEquals('192.168.1.1', $content);
    }

    public function testReplaceZoneInRecordContent(): void
    {
        $domain = 'example.com';
        $record = [
            'name' => '@',
            'content' => 'mail.example.com',
            'type' => 'CNAME'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals('@', $name);
        $this->assertEquals('mail.[ZONE]', $content);
    }

    public function testReplaceZoneWithTrailingDot(): void
    {
        $domain = 'example.com';
        $record = [
            'name' => 'subdomain.example.com',
            'content' => 'target.example.com',
            'type' => 'CNAME'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        // The pattern matches domain at the end of string (before optional dot)
        $this->assertEquals('subdomain.[ZONE]', $name);
        $this->assertEquals('target.[ZONE]', $content);
    }

    public function testReplaceSOARecordWithNS1Placeholder(): void
    {
        $domain = 'example.com';
        $record = [
            'name' => 'example.com',
            'content' => 'ns1.example.net hostmaster.example.com 2024010100 28800 7200 604800 86400',
            'type' => 'SOA'
        ];
        $options = [
            'NS1' => 'ns1.example.net',
            'HOSTMASTER' => 'hostmaster.example.com'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record, $options);

        $this->assertEquals('[ZONE]', $name);
        $this->assertStringContainsString('[NS1]', $content);
        $this->assertStringContainsString('[HOSTMASTER]', $content);
        $this->assertStringContainsString('[SERIAL]', $content);
    }

    public function testReplaceSOARecordSerialPlaceholder(): void
    {
        $domain = 'test.org';
        $record = [
            'name' => 'test.org',
            'content' => 'ns1.test.org admin.test.org 2024120501 28800 7200 604800 86400',
            'type' => 'SOA'
        ];
        $options = [
            'NS1' => 'ns1.test.org',
            'HOSTMASTER' => 'admin.test.org'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record, $options);

        // Serial (10 digits) should be replaced with [SERIAL]
        $this->assertStringContainsString('[SERIAL]', $content);
        $this->assertStringNotContainsString('2024120501', $content);
    }

    public function testReplaceDomainHyphenatedPattern(): void
    {
        $domain = 'example.com';
        $record = [
            'name' => '@',
            'content' => 'example-com.mail.protection.outlook.com',
            'type' => 'MX'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        // example-com should be replaced with [DOMAIN]-[TLD]
        $this->assertEquals('@', $name);
        $this->assertEquals('[DOMAIN]-[TLD].mail.protection.outlook.com', $content);
    }

    public function testReplaceMultiLevelSubdomain(): void
    {
        $domain = 'example.org';
        $record = [
            'name' => 'deep.sub.level.example.org',
            'content' => 'target.example.org',
            'type' => 'CNAME'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals('deep.sub.level.[ZONE]', $name);
        $this->assertEquals('target.[ZONE]', $content);
    }

    public function testEmptyDomainReturnsOriginal(): void
    {
        $domain = '';
        $record = [
            'name' => 'www.example.com',
            'content' => '192.168.1.1',
            'type' => 'A'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        // Should return original values when domain is empty
        $this->assertEquals('www.example.com', $name);
        $this->assertEquals('192.168.1.1', $content);
    }

    public function testTXTRecordPlaceholderReplacement(): void
    {
        $domain = 'example.com';
        $record = [
            'name' => 'example.com',
            'content' => 'mail.example.com',
            'type' => 'TXT'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        // Replacement only works for domain at the end of the string
        $this->assertEquals('[ZONE]', $name);
        $this->assertEquals('mail.[ZONE]', $content);
    }

    public function testMXRecordPlaceholderReplacement(): void
    {
        $domain = 'mysite.net';
        $record = [
            'name' => 'mysite.net',
            'content' => 'mail.mysite.net',
            'type' => 'MX'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals('[ZONE]', $name);
        $this->assertEquals('mail.[ZONE]', $content);
    }

    public function testNSRecordPlaceholderReplacement(): void
    {
        $domain = 'testzone.com';
        $record = [
            'name' => 'testzone.com',
            'content' => 'ns1.testzone.com',
            'type' => 'NS'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals('[ZONE]', $name);
        $this->assertEquals('ns1.[ZONE]', $content);
    }

    public function testIPAddressNotReplaced(): void
    {
        $domain = 'example.com';
        $record = [
            'name' => 'server.example.com',
            'content' => '192.168.1.100',
            'type' => 'A'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        // IP addresses should never be replaced
        $this->assertEquals('server.[ZONE]', $name);
        $this->assertEquals('192.168.1.100', $content);
    }

    public function testIPv6AddressNotReplaced(): void
    {
        $domain = 'example.net';
        $record = [
            'name' => 'ipv6.example.net',
            'content' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            'type' => 'AAAA'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals('ipv6.[ZONE]', $name);
        $this->assertEquals('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $content);
    }

    public function testCAARecordPlaceholderReplacement(): void
    {
        $domain = 'secure.com';
        $record = [
            'name' => 'secure.com',
            'content' => '0 issue "letsencrypt.org"',
            'type' => 'CAA'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        // CAA records should have name replaced but content preserved
        $this->assertEquals('[ZONE]', $name);
        $this->assertEquals('0 issue "letsencrypt.org"', $content);
    }

    public function testSRVRecordPlaceholderReplacement(): void
    {
        $domain = 'service.com';
        $record = [
            'name' => '_http._tcp.service.com',
            'content' => '10 80 server.service.com',
            'type' => 'SRV'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals('_http._tcp.[ZONE]', $name);
        $this->assertEquals('10 80 server.[ZONE]', $content);
    }

    public function testPartialDomainMatchNotReplaced(): void
    {
        $domain = 'example.com';
        $record = [
            'name' => 'other.domain.com',
            'content' => 'target.org',
            'type' => 'CNAME'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        // Should not replace when domain doesn't match
        $this->assertEquals('other.domain.com', $name);
        $this->assertEquals('target.org', $content);
    }

    public function testCaseSensitivityInReplacement(): void
    {
        $domain = 'Example.Com';
        $record = [
            'name' => 'www.Example.Com',
            'content' => 'mail.Example.Com',
            'type' => 'CNAME'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        // Should handle case-sensitive replacement
        $this->assertEquals('www.[ZONE]', $name);
        $this->assertEquals('mail.[ZONE]', $content);
    }

    public function testDMARCRecordPlaceholderReplacement(): void
    {
        $domain = 'company.org';
        $record = [
            'name' => '_dmarc.company.org',
            'content' => 'v=DMARC1; p=reject; rua=mailto:dmarc@company.org',
            'type' => 'TXT'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        $this->assertEquals('_dmarc.[ZONE]', $name);
        $this->assertStringContainsString('rua=mailto:dmarc@[ZONE]', $content);
    }

    public function testPTRRecordPlaceholderReplacement(): void
    {
        $domain = 'reverse.com';
        $record = [
            'name' => '1.0.168.192.in-addr.arpa',
            'content' => 'host.reverse.com',
            'type' => 'PTR'
        ];

        [$name, $content] = ZoneTemplate::replaceWithTemplatePlaceholders($domain, $record);

        // PTR name usually doesn't match domain, content should be replaced
        $this->assertEquals('1.0.168.192.in-addr.arpa', $name);
        $this->assertEquals('host.[ZONE]', $content);
    }
}
