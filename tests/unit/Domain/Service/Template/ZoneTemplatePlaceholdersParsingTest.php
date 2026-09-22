<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Template;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Template\ZoneTemplatePlaceholders;
use TestHelpers\FakeConfiguration;

class ZoneTemplatePlaceholdersParsingTest extends TestCase
{
    private ZoneTemplatePlaceholders $zoneTemplate;
    private $mockConfig;

    protected function setUp(): void
    {
        $this->mockConfig = new FakeConfiguration([
            'dns' => [
                'ns1' => 'ns1.example.com',
                'ns2' => 'ns2.example.com',
                'ns3' => 'ns3.example.com',
                'ns4' => 'ns4.example.com',
                'hostmaster' => 'hostmaster.example.com',
                'soa_refresh' => 28800,
                'soa_retry' => 7200,
                'soa_expire' => 604800,
                'soa_minimum' => 86400,
            ],
            'database' => ['pdns_db_name' => null],
        ]);

        $this->zoneTemplate = new ZoneTemplatePlaceholders($this->mockConfig);
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
     * A TXT record whose content merely contains the substring "SOA" must not
     * get SOA timers appended when the record type is passed explicitly.
     */
    public function testTxtContentContainingSoaGetsNoSoaTimers(): void
    {
        $result = $this->zoneTemplate->parseTemplateValue('"SOA monitor"', 'example.com', 'TXT');
        $this->assertEquals('"SOA monitor"', $result);
        $this->assertStringNotContainsString('28800 7200 604800 86400', $result);
    }

    /**
     * An SOA record with missing timers still gets them appended when the type
     * is passed explicitly.
     */
    public function testSoaContentGetsTimersWhenTypePassedExplicitly(): void
    {
        $result = $this->zoneTemplate->parseTemplateValue('[NS1] [HOSTMASTER] [SERIAL]', 'example.com', 'SOA');
        $this->assertStringContainsString('28800 7200 604800 86400', $result);
    }

    /**
     * Legacy 2-arg callers keep the substring heuristic: a value containing
     * "SOA" with fewer than 7 fields still gets timers appended.
     */
    public function testLegacyTwoArgFormRetainsSoaHeuristic(): void
    {
        $result = $this->zoneTemplate->parseTemplateValue('IN SOA [NS1] [HOSTMASTER] [SERIAL]', 'example.com');
        $this->assertStringContainsString('28800 7200 604800 86400', $result);
    }

    /**
     * [UNIXTIME] resolves to the current Unix timestamp and still gets SOA timers appended
     */
    public function testParseUnixtimePlaceholder(): void
    {
        $before = time();
        $result = $this->zoneTemplate->parseTemplateValue('[NS1] [HOSTMASTER] [UNIXTIME]', 'example.com', 'SOA');
        $after = time();

        $serial = (int)explode(' ', $result)[2];
        $this->assertGreaterThanOrEqual($before, $serial);
        $this->assertLessThanOrEqual($after, $serial);
        $this->assertStringContainsString('28800 7200 604800 86400', $result);
    }

    /**
     * [COUNTER] resolves to the initial counter value 1
     */
    public function testParseCounterPlaceholder(): void
    {
        $result = $this->zoneTemplate->parseTemplateValue('[NS1] [HOSTMASTER] [COUNTER]', 'example.com', 'SOA');

        $this->assertStringStartsWith('ns1.example.com hostmaster.example.com 1 ', $result);
        $this->assertStringContainsString('28800 7200 604800 86400', $result);
    }

    /**
     * Serial placeholders are substituted in non-SOA content too, like [SERIAL]
     */
    public function testUnixtimeAndCounterReplacedInNonSoaContent(): void
    {
        $result = $this->zoneTemplate->parseTemplateValue('"counter [COUNTER] time [UNIXTIME]"', 'example.com', 'TXT');

        $this->assertStringNotContainsString('[UNIXTIME]', $result);
        $this->assertStringNotContainsString('[COUNTER]', $result);
        $this->assertStringContainsString('"counter 1 time ', $result);
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

    public function testTxtContentIsQuotedForTheBackend(): void
    {
        $this->assertSame('"v=spf1 mx -all"', $this->zoneTemplate->parseTemplateValue('v=spf1 mx -all', 'example.com', 'TXT'));
        $this->assertSame('"already quoted"', $this->zoneTemplate->parseTemplateValue('"already quoted"', 'example.com', 'TXT'));
        $this->assertSame('"say \\"hi\\" to example.com"', $this->zoneTemplate->parseTemplateValue('say "hi" to [ZONE]', 'example.com', 'TXT'));
        $this->assertSame('', $this->zoneTemplate->parseTemplateValue('', 'example.com', 'TXT'));
        $this->assertSame('"C:\\\\path"', $this->zoneTemplate->parseTemplateValue('C:\\path', 'example.com', 'TXT'));
        // Other types and the legacy two-argument call are untouched
        $this->assertSame('v=spf1 mx -all', $this->zoneTemplate->parseTemplateValue('v=spf1 mx -all', 'example.com', 'SPF'));
        $this->assertSame('v=spf1 mx -all', $this->zoneTemplate->parseTemplateValue('v=spf1 mx -all', 'example.com'));
    }
}
