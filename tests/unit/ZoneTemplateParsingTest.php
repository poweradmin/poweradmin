<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Database\PDOCommon;
use unit\MockConfiguration;

class ZoneTemplateParsingTest extends TestCase
{
    private ZoneTemplate $zoneTemplate;
    private $mockDb;
    private $mockConfig;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(PDOCommon::class);
        $this->mockConfig = new MockConfiguration();

        $this->zoneTemplate = new ZoneTemplate($this->mockDb, $this->mockConfig);
    }

    /**
     * Test parsing ZONE placeholder
     */
    public function testParseZonePlaceholder(): void
    {
        $template = '[ZONE] IN A 192.168.1.1';
        $result = $this->zoneTemplate->parseTemplateValue($template, 'example.com');
        $this->assertEquals('example.com IN A 192.168.1.1', $result);
    }

    /**
     * Test parsing DOMAIN and TLD placeholders for simple domain
     */
    public function testParseDomainAndTldPlaceholders(): void
    {
        $template = '[DOMAIN]-[TLD].mail.protection.outlook.com';
        $result = $this->zoneTemplate->parseTemplateValue($template, 'example.net');
        $this->assertEquals('example-net.mail.protection.outlook.com', $result);
    }

    /**
     * Test parsing DOMAIN and TLD placeholders for subdomain
     */
    public function testParseDomainAndTldPlaceholdersWithSubdomain(): void
    {
        $template = '[DOMAIN]-[TLD].mail.protection.outlook.com';
        $result = $this->zoneTemplate->parseTemplateValue($template, 'subdomain.example.net');
        $this->assertEquals('example-net.mail.protection.outlook.com', $result);
    }

    /**
     * Test Microsoft 365 MX record use case
     */
    public function testMicrosoft365MxRecord(): void
    {
        $template = '[ZONE] IN MX 0 [DOMAIN]-[TLD].mail.protection.outlook.com';
        $result = $this->zoneTemplate->parseTemplateValue($template, 'example.net');
        $this->assertEquals('example.net IN MX 0 example-net.mail.protection.outlook.com', $result);
    }

    /**
     * Test with compound TLD
     */
    public function testParseWithCompoundTld(): void
    {
        $template = '[DOMAIN]-[TLD] record';
        $result = $this->zoneTemplate->parseTemplateValue($template, 'example.co.uk');
        // The TLD should be 'co.uk' with dots preserved
        $this->assertEquals('example-co.uk record', $result);
    }

    /**
     * Test with reverse DNS zone (should not apply DOMAIN/TLD parsing)
     */
    public function testParseReverseDnsZone(): void
    {
        $template = '[ZONE] PTR [DOMAIN]-[TLD].example.com';
        $result = $this->zoneTemplate->parseTemplateValue($template, '10.168.192.in-addr.arpa');
        $this->assertEquals('10.168.192.in-addr.arpa PTR 10.168.192.in-addr.arpa-.example.com', $result);
    }

    /**
     * Test all placeholders together
     */
    public function testParseAllPlaceholders(): void
    {
        $template = '[ZONE] IN MX 0 [DOMAIN]-[TLD].mail.protection.outlook.com ; [NS1] [SERIAL]';
        $result = $this->zoneTemplate->parseTemplateValue($template, 'example.net');

        // Verify all placeholders are replaced
        $this->assertStringContainsString('example.net IN MX 0 example-net.mail.protection.outlook.com', $result);
        $this->assertStringContainsString('ns1.example.com', $result);
        $this->assertStringContainsString(date('Ymd'), $result); // Serial contains today's date
    }

    /**
     * Test SOA record with all placeholders
     */
    public function testParseSoaRecord(): void
    {
        $template = '[ZONE] IN SOA [NS1] [HOSTMASTER] [SERIAL]';
        $result = $this->zoneTemplate->parseTemplateValue($template, 'example.com');

        // Verify the base parts are correct
        $this->assertStringStartsWith('example.com IN SOA ns1.example.com hostmaster.example.com ' . date('Ymd') . '00', $result);

        // Also verify that the SOA parameters are present
        $this->assertStringContainsString('28800 7200 604800 86400', $result);
    }

    /**
     * Test that dots in TLD are preserved when using [TLD] placeholder
     */
    public function testDotsInTldPreserved(): void
    {
        // For [DOMAIN]-[TLD], the dots in TLD should remain as-is
        $template = '[DOMAIN]-[TLD]';
        $result = $this->zoneTemplate->parseTemplateValue($template, 'example.co.uk');
        $this->assertEquals('example-co.uk', $result);

        // Ensure the original [ZONE] placeholder still works normally
        $template2 = '[ZONE]';
        $result2 = $this->zoneTemplate->parseTemplateValue($template2, 'example.co.uk');
        $this->assertEquals('example.co.uk', $result2);
    }
}
