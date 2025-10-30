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
}
