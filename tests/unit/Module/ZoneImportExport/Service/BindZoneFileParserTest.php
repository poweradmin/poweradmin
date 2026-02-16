<?php

namespace Tests\Unit\Module\ZoneImportExport\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Module\ZoneImportExport\Service\BindZoneFileParser;

class BindZoneFileParserTest extends TestCase
{
    private BindZoneFileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BindZoneFileParser(300);
    }

    public function testParseSimpleZoneFile(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600

@    IN  SOA  ns1.example.com. admin.example.com. 2024010101 3600 900 1209600 86400
@    IN  NS   ns1.example.com.
@    IN  NS   ns2.example.com.
@    IN  A    192.0.2.1
www  IN  A    192.0.2.2
mail IN  MX   10 mail.example.com.
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals('example.com', $result->getOrigin());
        $this->assertEquals(3600, $result->getDefaultTtl());
        $this->assertEquals(6, $result->getRecordCount());
        $this->assertEmpty($result->getWarnings());
    }

    public function testParseOriginDirective(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 86400
www  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $this->assertEquals('example.com', $result->getOrigin());

        $records = $result->getRecords();
        $this->assertEquals('www.example.com', $records[0]->name);
    }

    public function testParseTtlDirective(): void
    {
        $content = <<<ZONE
\$TTL 7200
example.com.  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $this->assertEquals(7200, $result->getDefaultTtl());

        $records = $result->getRecords();
        $this->assertEquals(7200, $records[0]->ttl);
    }

    public function testParseTtlWithSuffix(): void
    {
        $this->assertEquals(3600, $this->parser->parseTtl('1h'));
        $this->assertEquals(86400, $this->parser->parseTtl('1d'));
        $this->assertEquals(604800, $this->parser->parseTtl('1w'));
        $this->assertEquals(300, $this->parser->parseTtl('5m'));
        $this->assertEquals(60, $this->parser->parseTtl('60s'));
        $this->assertEquals(90000, $this->parser->parseTtl('1d1h'));
    }

    public function testParseRecordWithExplicitTtl(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 86400
www  300  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();
        $this->assertEquals(300, $records[0]->ttl);
    }

    public function testParseCloudflareAutoTtl(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 1
www  1  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();
        // TTL=1 (Cloudflare "auto") mapped to configured default (300)
        $this->assertEquals(300, $records[0]->ttl);
    }

    public function testParseMxRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  MX  10 mail.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('MX', $records[0]->type);
        $this->assertEquals(10, $records[0]->priority);
        $this->assertEquals('mail.example.com', $records[0]->content);
    }

    public function testParseSrvRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
_sip._tcp  IN  SRV  10 60 5060 sip.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('SRV', $records[0]->type);
        $this->assertEquals(10, $records[0]->priority);
        $this->assertEquals('60 5060 sip.example.com', $records[0]->content);
    }

    public function testParseTxtRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  TXT  "v=spf1 include:_spf.google.com ~all"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('TXT', $records[0]->type);
        $this->assertStringContainsString('v=spf1', $records[0]->content);
    }

    public function testParseCnameRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www  IN  CNAME  example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('CNAME', $records[0]->type);
        $this->assertEquals('example.com', $records[0]->content);
    }

    public function testParseMultilineRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 86400
@  IN  SOA  ns1.example.com. admin.example.com. (
    2024010101  ; serial
    3600        ; refresh
    900         ; retry
    1209600     ; expire
    86400       ; minimum
)
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('SOA', $records[0]->type);
        $this->assertStringContainsString('ns1.example.com', $records[0]->content);
        $this->assertStringContainsString('2024010101', $records[0]->content);
    }

    public function testParseAtSymbol(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('example.com', $records[0]->name);
    }

    public function testParseRelativeNames(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www     IN  A  192.0.2.1
mail    IN  A  192.0.2.2
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('www.example.com', $records[0]->name);
        $this->assertEquals('mail.example.com', $records[1]->name);
    }

    public function testParseFullyQualifiedNames(): void
    {
        $content = <<<ZONE
\$TTL 3600
other.example.org.  IN  A  192.0.2.99
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('other.example.org', $records[0]->name);
    }

    public function testParseLeadingWhitespaceReusesName(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@     IN  NS  ns1.example.com.
      IN  NS  ns2.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(2, count($records));
        $this->assertEquals('example.com', $records[0]->name);
        $this->assertEquals('example.com', $records[1]->name);
    }

    public function testParseCommentsStripped(): void
    {
        $content = <<<ZONE
; This is a comment
\$ORIGIN example.com.
\$TTL 3600
www  IN  A  192.0.2.1 ; inline comment
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertEquals('192.0.2.1', $records[0]->content);
    }

    public function testParseCloudflareProxiedComment(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 1
www  1  IN  A  192.0.2.1 ;cf-proxied:true
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('192.0.2.1', $records[0]->content);
    }

    public function testParseSkipsUnparseableLines(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
this is not a valid record line at all
www  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertNotEmpty($result->getWarnings());
    }

    public function testParseCaaRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  CAA  0 issue "letsencrypt.org"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('CAA', $records[0]->type);
        $this->assertStringContainsString('letsencrypt.org', $records[0]->content);
    }

    public function testParseAaaaRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  AAAA  2001:db8::1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('AAAA', $records[0]->type);
        $this->assertEquals('2001:db8::1', $records[0]->content);
    }

    public function testParseUnsupportedDirectiveWarning(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
\$GENERATE 1-10 host\$ IN A 192.0.2.\$
www  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('$GENERATE', $result->getWarnings()[0]);
    }

    public function testParseEmptyContent(): void
    {
        $result = $this->parser->parse('');
        $this->assertEquals(0, $result->getRecordCount());
        $this->assertEmpty($result->getWarnings());
    }

    public function testParseTtlAndClassReversed(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www  IN  300  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        // Class before TTL should still parse
        $this->assertGreaterThanOrEqual(1, $result->getRecordCount());
        $this->assertEquals('A', $records[0]->type);
    }

    public function testParseCompoundTtlInRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www  1d1h  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertEquals('A', $records[0]->type);
        $this->assertEquals(90000, $records[0]->ttl);
    }

    public function testParseCompoundTtlDirective(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 1w2d
www  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals(777600, $result->getDefaultTtl());
        $records = $result->getRecords();
        $this->assertEquals(777600, $records[0]->ttl);
    }

    public function testParseParenthesesPreservedInTxtContent(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  TXT  "v=spf1 ip4:192.0.2.0/24 (comment) ~all"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertEquals('TXT', $records[0]->type);
        $this->assertStringContainsString('(comment)', $records[0]->content);
    }

    public function testParseParenthesesPreservedInCaaRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  CAA  0 issue "letsencrypt.org; accounturi=https://acme.example.com/acct/1 (test)"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertEquals('CAA', $records[0]->type);
        $this->assertStringContainsString('(test)', $records[0]->content);
    }

    public function testParseMultilineSoaStripsParens(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 86400
@  IN  SOA  ns1.example.com. admin.example.com. (
    2024010101
    3600
    900
    1209600
    86400
)
@  IN  TXT  "text with (parens) inside"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        // SOA should parse correctly with parens stripped
        $this->assertEquals('SOA', $records[0]->type);
        $this->assertStringNotContainsString('(', $records[0]->content);

        // TXT should preserve parens in quoted content
        $this->assertEquals('TXT', $records[1]->type);
        $this->assertStringContainsString('(parens)', $records[1]->content);
    }

    public function testIsTtlValueCompoundForms(): void
    {
        // Test via parsing records with compound TTLs
        $testCases = [
            ['ttl' => '1d1h', 'expected' => 90000],
            ['ttl' => '2h30m', 'expected' => 9000],
            ['ttl' => '1w2d3h', 'expected' => 788400],
        ];

        foreach ($testCases as $case) {
            $content = "\$ORIGIN example.com.\n\$TTL 3600\nwww {$case['ttl']} IN A 192.0.2.1\n";
            $result = $this->parser->parse($content);
            $records = $result->getRecords();

            $this->assertEquals(1, $result->getRecordCount(), "Failed for TTL: {$case['ttl']}");
            $this->assertEquals($case['expected'], $records[0]->ttl, "Wrong TTL value for: {$case['ttl']}");
        }
    }
}
