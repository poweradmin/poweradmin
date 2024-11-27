<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\DnsHelper;

class DnsHelperTest extends TestCase
{
    public function testIsReverseZonePositiveCases(): void
    {
        $this->assertTrue(DnsHelper::isReverseZone('1.0.0.127.in-addr.arpa'), 'Should return true for IPv4 reverse zone.');
        $this->assertTrue(DnsHelper::isReverseZone('0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa'), 'Should return true for IPv6 reverse zone.');
    }

    public function testIsReverseZoneNegativeCases(): void
    {
        $this->assertFalse(DnsHelper::isReverseZone('example.com'), 'Should return false for a regular domain.');
        $this->assertFalse(DnsHelper::isReverseZone('subdomain.example.com'), 'Should return false for a subdomain.');
        $this->assertFalse(DnsHelper::isReverseZone('example.in-addr.arpa.com'), 'Should return false for a domain containing in-addr.arpa but not a reverse zone.');
    }

    public function testIsReverseZoneCornerCases(): void
    {
        $this->assertFalse(DnsHelper::isReverseZone(''), 'Should return false for an empty string.');
        $this->assertFalse(DnsHelper::isReverseZone(' '), 'Should return false for a string with only whitespace.');
        $this->assertFalse(DnsHelper::isReverseZone('in-addr.arpa'), 'Should return false for a string with only in-addr.arpa.');
        $this->assertFalse(DnsHelper::isReverseZone('ip6.arpa'), 'Should return false for a string with only ip6.arpa.');
        $this->assertFalse(DnsHelper::isReverseZone('1.0.0.127.in-addr.arpa '), 'Should return false for a reverse zone with trailing whitespace.');
        $this->assertFalse(DnsHelper::isReverseZone(' 1.0.0.127.in-addr.arpa'), 'Should return false for a reverse zone with leading whitespace.');
    }

    public function testGetRegisteredDomainWithSimpleDomain()
    {
        $this->assertEquals('example.com', DnsHelper::getRegisteredDomain('example.com'));
    }

    public function testGetRegisteredDomainWithSubdomain()
    {
        $this->assertEquals('example.com', DnsHelper::getRegisteredDomain('sub.example.com'));
    }

    public function testGetRegisteredDomainWithMultipleSubdomains()
    {
        $this->assertEquals('example.com', DnsHelper::getRegisteredDomain('sub.sub2.example.com'));
    }

    public function testGetRegisteredDomainWithCountryCodeTLD()
    {
        $this->assertEquals('example.co.uk', DnsHelper::getRegisteredDomain('sub.example.co.uk'));
    }

//    public function testGetRegisteredDomainWithSinglePartDomain()
//    {
//        $this->assertEquals('localhost', DnsHelper::getRegisteredDomain('localhost'));
//    }

    public function testGetDomainNameWithSubdomain()
    {
        $this->assertEquals('sub', DnsHelper::getSubDomainName('sub.example.com'));
    }

    public function testGetDomainNameWithoutSubdomain()
    {
        $this->assertEquals('example.com', DnsHelper::getSubDomainName('example.com'));
    }

    public function testGetDomainNameWithMultipleSubdomains()
    {
        $this->assertEquals('sub.sub', DnsHelper::getSubDomainName('sub.sub.example.com'));
    }

    public function testGetDomainNameWithSinglePartDomain()
    {
        $this->assertEquals('localhost', DnsHelper::getSubDomainName('localhost'));
    }

    public function testGetDomainNameWithTwoPartDomain()
    {
        $this->assertEquals('example', DnsHelper::getSubDomainName('example.co.uk'));
    }
}
