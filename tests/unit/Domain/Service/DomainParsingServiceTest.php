<?php

namespace Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DomainParsingService;

class DomainParsingServiceTest extends TestCase
{
    private DomainParsingService $service;

    protected function setUp(): void
    {
        $this->service = new DomainParsingService();
    }

    /**
     * Test parsing a simple domain with common TLD
     */
    public function testParseSimpleDomain(): void
    {
        $result = $this->service->parseDomain('example.net');
        $this->assertEquals('example', $result['domain']);
        $this->assertEquals('net', $result['tld']);
    }

    /**
     * Test parsing a domain with subdomain
     */
    public function testParseDomainWithSubdomain(): void
    {
        $result = $this->service->parseDomain('subdomain.example.net');
        $this->assertEquals('example', $result['domain']);
        $this->assertEquals('net', $result['tld']);
    }

    /**
     * Test parsing a domain with multiple subdomains
     */
    public function testParseDomainWithMultipleSubdomains(): void
    {
        $result = $this->service->parseDomain('sub1.sub2.example.net');
        $this->assertEquals('example', $result['domain']);
        $this->assertEquals('net', $result['tld']);
    }

    /**
     * Test parsing domain with compound TLD
     */
    public function testParseDomainWithCompoundTld(): void
    {
        $result = $this->service->parseDomain('example.co.uk');
        $this->assertEquals('example', $result['domain']);
        $this->assertEquals('co.uk', $result['tld']);
    }

    /**
     * Test parsing subdomain with compound TLD
     */
    public function testParseSubdomainWithCompoundTld(): void
    {
        $result = $this->service->parseDomain('subdomain.example.co.uk');
        $this->assertEquals('example', $result['domain']);
        $this->assertEquals('co.uk', $result['tld']);
    }

    /**
     * Test parsing reverse DNS zone
     */
    public function testParseReverseDnsZone(): void
    {
        $result = $this->service->parseDomain('10.168.192.in-addr.arpa');
        $this->assertEquals('10.168.192.in-addr.arpa', $result['domain']);
        $this->assertEquals('', $result['tld']);
    }

    /**
     * Test parsing IP address
     */
    public function testParseIpAddress(): void
    {
        $result = $this->service->parseDomain('192.168.10.1');
        $this->assertEquals('192.168.10.1', $result['domain']);
        $this->assertEquals('', $result['tld']);
    }

    /**
     * Test parsing single-part domain (no TLD)
     */
    public function testParseSinglePartDomain(): void
    {
        $result = $this->service->parseDomain('localhost');
        $this->assertEquals('localhost', $result['domain']);
        $this->assertEquals('', $result['tld']);
    }

    /**
     * Test Microsoft 365 use case
     * For zone example.net, we should get domain=example, tld=net
     * So the MX record template [DOMAIN]-[TLD].mail.protection.outlook.com
     * would become example-net.mail.protection.outlook.com
     */
    public function testMicrosoft365UseCase(): void
    {
        $result = $this->service->parseDomain('example.net');
        $this->assertEquals('example', $result['domain']);
        $this->assertEquals('net', $result['tld']);

        // Test with the template pattern
        $mxRecord = $result['domain'] . '-' . $result['tld'] . '.mail.protection.outlook.com';
        $this->assertEquals('example-net.mail.protection.outlook.com', $mxRecord);
    }

    /**
     * Test Microsoft 365 use case with subdomain
     */
    public function testMicrosoft365UseCaseWithSubdomain(): void
    {
        $result = $this->service->parseDomain('subdomain.example.net');
        $this->assertEquals('example', $result['domain']);
        $this->assertEquals('net', $result['tld']);

        // Even with subdomain, we get the base domain parts
        $mxRecord = $result['domain'] . '-' . $result['tld'] . '.mail.protection.outlook.com';
        $this->assertEquals('example-net.mail.protection.outlook.com', $mxRecord);
    }
}
