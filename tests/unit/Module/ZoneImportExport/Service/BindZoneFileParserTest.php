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

    // --- inferOrigin tests ---

    public function testInferOriginFromFqdnRecords(): void
    {
        $content = <<<ZONE
a.example.com.  60  IN  A  1.1.1.1
b.example.com.  60  IN  A  1.1.1.2
c.example.com.  60  IN  A  1.1.1.3
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals('example.com', $result->getOrigin());
    }

    public function testInferOriginMixedDepths(): void
    {
        $content = <<<ZONE
example.com.      60  IN  A     1.1.1.1
www.example.com.  60  IN  A     1.1.1.2
mail.example.com. 60  IN  A     1.1.1.3
deep.sub.example.com. 60 IN  A  1.1.1.4
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals('example.com', $result->getOrigin());
    }

    public function testInferOriginSingleNameMultipleRecords(): void
    {
        $content = <<<ZONE
example.com.  60  IN  A   1.1.1.1
example.com.  60  IN  MX  10 mail.example.com.
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals('example.com', $result->getOrigin());
    }

    public function testInferOriginReturnsNullForUnrelatedDomains(): void
    {
        $content = <<<ZONE
a.example.com.  60  IN  A  1.1.1.1
b.other.org.    60  IN  A  1.1.1.2
ZONE;

        $result = $this->parser->parse($content);

        $this->assertNull($result->getOrigin());
    }

    public function testInferOriginReturnsNullForOnlyTldInCommon(): void
    {
        $content = <<<ZONE
a.example.com.  60  IN  A  1.1.1.1
b.another.com.  60  IN  A  1.1.1.2
ZONE;

        $result = $this->parser->parse($content);

        $this->assertNull($result->getOrigin());
    }

    public function testInferOriginSubdomainZone(): void
    {
        $content = <<<ZONE
a.sub.example.com.  60  IN  A  1.1.1.1
b.sub.example.com.  60  IN  A  1.1.1.2
c.sub.example.com.  60  IN  A  1.1.1.3
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals('sub.example.com', $result->getOrigin());
    }

    public function testInferOriginNotUsedWhenOriginDirectivePresent(): void
    {
        $content = <<<ZONE
\$ORIGIN myzone.example.com.
\$TTL 3600
www  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals('myzone.example.com', $result->getOrigin());
    }

    public function testInferOriginCaseInsensitive(): void
    {
        $content = <<<ZONE
a.Example.COM.  60  IN  A  1.1.1.1
b.example.com.  60  IN  A  1.1.1.2
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals('example.com', $result->getOrigin());
    }

    public function testInferOriginWithCommentsAndTags(): void
    {
        $content = <<<ZONE
a.example.com.  60  IN  A  1.1.1.1 ; cf_tags=awesome
b.example.com.  60  IN  A  1.1.1.2 ; just a comment
c.example.com.  60  IN  A  1.1.1.3 ; simple example cf_tags=important
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals('example.com', $result->getOrigin());
        $this->assertEquals(3, $result->getRecordCount());
    }

    public function testInferOriginDirectMethodCall(): void
    {
        $records = [
            new \Poweradmin\Module\ZoneImportExport\Service\ParsedRecord('a.example.com', 60, 'A', '1.1.1.1'),
            new \Poweradmin\Module\ZoneImportExport\Service\ParsedRecord('b.example.com', 60, 'A', '1.1.1.2'),
            new \Poweradmin\Module\ZoneImportExport\Service\ParsedRecord('c.example.com', 60, 'A', '1.1.1.3'),
        ];

        $this->assertEquals('example.com', $this->parser->inferOrigin($records));
    }

    public function testInferOriginReturnsNullForEmptyRecords(): void
    {
        $this->assertNull($this->parser->inferOrigin([]));
    }

    public function testInferOriginReturnsNullForSingleLabelNames(): void
    {
        $records = [
            new \Poweradmin\Module\ZoneImportExport\Service\ParsedRecord('localhost', 60, 'A', '127.0.0.1'),
        ];

        $this->assertNull($this->parser->inferOrigin($records));
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

    // =========================================================================
    // BIND9-inspired tests (based on bind9 test fixtures)
    // =========================================================================

    // --- Tab-separated fields and lowercase class (master1.data) ---

    public function testParseTabSeparatedFieldsWithLowercaseClass(): void
    {
        $content = "\$ORIGIN test.\n\$TTL 1000\n@\t\tin\tns\tns.vix.com.\nb\t\tin\ta\t1.2.3.4\n";

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        // SOA not present, so we should have NS and A
        $this->assertGreaterThanOrEqual(2, $result->getRecordCount());

        $nsRecord = null;
        $aRecord = null;
        foreach ($records as $r) {
            if ($r->type === 'NS') {
                $nsRecord = $r;
            }
            if ($r->type === 'A') {
                $aRecord = $r;
            }
        }

        $this->assertNotNull($nsRecord);
        $this->assertEquals('test', $nsRecord->name);
        $this->assertEquals('ns.vix.com', $nsRecord->content);

        $this->assertNotNull($aRecord);
        $this->assertEquals('b.test', $aRecord->name);
        $this->assertEquals('1.2.3.4', $aRecord->content);
    }

    // --- No $TTL directive — uses default TTL (master4.data) ---

    public function testParseWithoutTtlDirectiveUsesDefault(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
www  IN  A  192.0.2.1
mail IN  A  192.0.2.2
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        // Default TTL is 86400 when no $TTL directive
        $this->assertEquals(86400, $result->getDefaultTtl());
        $this->assertEquals(86400, $records[0]->ttl);
        $this->assertEquals(86400, $records[1]->ttl);
    }

    // --- Blank lines with spaces (master10.data) ---

    public function testParseBlankLinesWithSpaces(): void
    {
        // Lines containing only spaces should be skipped like empty lines
        $content = "\$ORIGIN example.com.\n\$TTL 300\n   \n@\t300\tIN\tA\t10.0.0.1\n";

        $result = $this->parser->parse($content);

        $this->assertEquals(1, $result->getRecordCount());
        $records = $result->getRecords();
        $this->assertEquals('A', $records[0]->type);
        $this->assertEquals('10.0.0.1', $records[0]->content);
    }

    // --- $ORIGIN change mid-file with inherited owner (master17.data) ---

    public function testParseOriginChangeMidFile(): void
    {
        $content = <<<ZONE
\$ORIGIN test.
\$TTL 1000
@  IN  NS  ns.test.
b  IN  A   1.2.3.4
\$ORIGIN sub.test.
c  IN  A   4.3.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        // First records are under test. origin
        $this->assertEquals('test', $records[0]->name);
        $this->assertEquals('ns.test', $records[0]->content);

        $this->assertEquals('b.test', $records[1]->name);

        // After $ORIGIN change, relative names resolve under sub.test.
        $this->assertEquals('c.sub.test', $records[2]->name);
    }

    // --- Multiple $ORIGIN changes with return (example2.db) ---

    public function testParseMultipleOriginChanges(): void
    {
        $content = <<<ZONE
\$ORIGIN example.
\$TTL 300
a        IN  A   10.0.0.2
\$ORIGIN s.example.
ns       IN  A   73.80.65.49
\$ORIGIN example.
u        IN  TXT "txt-not-in-nxt"
\$ORIGIN u.example.
a        IN  A   73.80.65.49
b        IN  A   73.80.65.49
\$ORIGIN example.
wks      IN  A   10.0.0.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('a.example', $records[0]->name);
        $this->assertEquals('ns.s.example', $records[1]->name);
        $this->assertEquals('u.example', $records[2]->name);
        $this->assertEquals('a.u.example', $records[3]->name);
        $this->assertEquals('b.u.example', $records[4]->name);
        $this->assertEquals('wks.example', $records[5]->name);
    }

    // --- $ORIGIN with root (.) (example2.db) ---

    public function testParseOriginRoot(): void
    {
        $content = <<<ZONE
\$ORIGIN .
\$TTL 300
example.  IN  A  10.0.0.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertEquals('example', $records[0]->name);
    }

    // --- TTL of zero (min-example.db) ---

    public function testParseTtlZero(): void
    {
        $content = <<<ZONE
\$ORIGIN min-example.
\$TTL 0
ns  IN  A  10.53.0.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        // TTL=0 is valid (but gets mapped to autoTtlValue? No, only TTL=1 is auto)
        // Actually TTL=0 is legitimate — it means "do not cache"
        $this->assertEquals(0, $records[0]->ttl);
    }

    // --- Odd TTL value (301 = 5 minutes 1 second, example2.db) ---

    public function testParseOddTtlValue(): void
    {
        $content = <<<ZONE
\$ORIGIN example.
\$TTL 301
t  IN  A  73.80.65.49
ZONE;

        $result = $this->parser->parse($content);
        $this->assertEquals(301, $result->getDefaultTtl());
        $this->assertEquals(301, $result->getRecords()[0]->ttl);
    }

    // --- $TTL change mid-zone (example2.db) ---

    public function testParseTtlChangeMidZone(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 300
a    IN  A  10.0.0.2
\$TTL 3600
a01  IN  A  0.0.0.0
\$TTL 300
b    IN  A  73.80.65.49
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(300, $records[0]->ttl);
        $this->assertEquals(3600, $records[1]->ttl);
        $this->assertEquals(300, $records[2]->ttl);
    }

    // --- Boundary IP addresses (example2.db) ---

    public function testParseBoundaryIpAddresses(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
zero  IN  A     0.0.0.0
max   IN  A     255.255.255.255
ipv6  IN  AAAA  ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('0.0.0.0', $records[0]->content);
        $this->assertEquals('255.255.255.255', $records[1]->content);
        $this->assertEquals('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', $records[2]->content);
    }

    // --- Wildcard records (wildcard/ns1/example.db.in) ---

    public function testParseWildcardRecords(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
*.example.com.  IN  TXT  "this is a wildcard"
*.example.com.  IN  MX   10 host1.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(2, $result->getRecordCount());
        $this->assertEquals('*.example.com', $records[0]->name);
        $this->assertEquals('TXT', $records[0]->type);
        $this->assertEquals('*.example.com', $records[1]->name);
        $this->assertEquals('MX', $records[1]->type);
    }

    public function testParseWildcardRelativeName(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
*     IN  A     192.0.2.1
*     IN  TXT   "wildcard"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('*.example.com', $records[0]->name);
        $this->assertEquals('*.example.com', $records[1]->name);
    }

    public function testParseSubWildcard(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
sub.*  IN  TXT  "this is not a wildcard"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('sub.*.example.com', $records[0]->name);
    }

    public function testParseServiceWildcard(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
_foo._udp.*  IN  SRV  0 1 9 old-slow-box.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('_foo._udp.*.example.com', $records[0]->name);
        $this->assertEquals('SRV', $records[0]->type);
    }

    // --- CNAME/DNAME to root and relative targets (example2.db) ---

    public function testParseCnameToRoot(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
cname03  IN  CNAME  .
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('CNAME', $records[0]->type);
        // Root "." becomes empty string after rtrim
        $this->assertEquals('', $records[0]->content);
    }

    public function testParseCnameRelativeTarget(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
alias  IN  CNAME  cname-target
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('CNAME', $records[0]->type);
        $this->assertEquals('cname-target.example.com', $records[0]->content);
    }

    public function testParseDnameRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
dname01  IN  DNAME  dname-target.example.com.
dname02  IN  DNAME  dname-target
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('DNAME', $records[0]->type);
        $this->assertEquals('dname-target.example.com', $records[0]->content);

        $this->assertEquals('DNAME', $records[1]->type);
        $this->assertEquals('dname-target.example.com', $records[1]->content);
    }

    // --- Multiple TXT strings (example2.db) ---

    public function testParseMultipleTxtStrings(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
txt02  IN  TXT  "foo" "bar"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('TXT', $records[0]->type);
        $this->assertStringContainsString('foo', $records[0]->content);
        $this->assertStringContainsString('bar', $records[0]->content);
    }

    public function testParseTxtWithSpaces(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
txt05  IN  TXT  "foo bar"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('TXT', $records[0]->type);
        $this->assertStringContainsString('foo bar', $records[0]->content);
    }

    public function testParseTxtWithEscapedQuotes(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
txt11  IN  TXT  "\"foo\""
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('TXT', $records[0]->type);
        $this->assertStringContainsString('\"foo\"', $records[0]->content);
    }

    // --- Multiple record types at same name (example2.db) ---

    public function testParseMultipleTypesAtSameName(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 300
e  IN  MX   10 mail.example.com.
   IN  TXT  "one"
   IN  TXT  "three"
   IN  A    73.80.65.49
   IN  A    73.80.65.50
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(5, $result->getRecordCount());
        // All records should have the same name (inherited via leading whitespace)
        foreach ($records as $record) {
            $this->assertEquals('e.example.com', $record->name);
        }

        $this->assertEquals('MX', $records[0]->type);
        $this->assertEquals('TXT', $records[1]->type);
        $this->assertEquals('TXT', $records[2]->type);
        $this->assertEquals('A', $records[3]->type);
        $this->assertEquals('A', $records[4]->type);
    }

    // --- AFSDB records (example2.db) ---

    public function testParseAfsdbRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
afsdb01  IN  AFSDB  0 hostname.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('AFSDB', $records[0]->type);
        $this->assertEquals(0, $records[0]->priority);
        $this->assertEquals('hostname.example.com', $records[0]->content);
    }

    // --- KX records (example2.db) ---

    public function testParseKxRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
kx01  IN  KX  10 kdc.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('KX', $records[0]->type);
        $this->assertEquals(10, $records[0]->priority);
        $this->assertEquals('kdc.example.com', $records[0]->content);
    }

    // --- NAPTR records (example2.db) ---

    public function testParseNaptrRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
naptr02  IN  NAPTR  65535 65535 "blurgh" "blorf" "blllbb" foo.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('NAPTR', $records[0]->type);
        $this->assertStringContainsString('65535', $records[0]->content);
        $this->assertStringContainsString('"blurgh"', $records[0]->content);
        $this->assertStringContainsString('foo.example.com', $records[0]->content);
    }

    // --- SRV records with edge values (example2.db) ---

    public function testParseSrvRecordZeroValues(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
srv01  IN  SRV  0 0 0 .
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('SRV', $records[0]->type);
        $this->assertEquals(0, $records[0]->priority);
        // Weight=0, Port=0, Target=. (root, empty after rtrim)
        $this->assertStringContainsString('0 0', $records[0]->content);
    }

    public function testParseSrvRecordMaxValues(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
srv02  IN  SRV  65535 65535 65535 old-slow-box.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('SRV', $records[0]->type);
        $this->assertEquals(65535, $records[0]->priority);
        $this->assertStringContainsString('65535 65535', $records[0]->content);
        $this->assertStringContainsString('old-slow-box.example.com', $records[0]->content);
    }

    // --- MX record with dot target (example2.db) ---

    public function testParseMxRecordWithDotTarget(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
mx02  IN  MX  10 .
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('MX', $records[0]->type);
        $this->assertEquals(10, $records[0]->priority);
    }

    // --- MX record with relative target ---

    public function testParseMxRecordRelativeTarget(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  MX  0 mail
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('MX', $records[0]->type);
        $this->assertEquals('mail.example.com', $records[0]->content);
    }

    // --- Records without explicit class (name TTL TYPE RDATA) ---

    public function testParseRecordWithoutClass(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www  300  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertEquals('A', $records[0]->type);
        $this->assertEquals(300, $records[0]->ttl);
        $this->assertEquals('192.0.2.1', $records[0]->content);
    }

    // --- Records with just name TYPE RDATA (no TTL, no class) ---

    public function testParseRecordWithoutTtlOrClass(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertEquals('A', $records[0]->type);
        $this->assertEquals(3600, $records[0]->ttl);
        $this->assertEquals('192.0.2.1', $records[0]->content);
    }

    // --- TLSA record (mx.db) ---

    public function testParseTlsaRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 86400
_25._tcp.mail  IN  TLSA  3 0 1 5B30F9602297D558EB719162C225088184FAA32CA45E1ED15DE58A21D9FCE383
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('TLSA', $records[0]->type);
        $this->assertEquals('_25._tcp.mail.example.com', $records[0]->name);
        $this->assertStringContainsString('3 0 1', $records[0]->content);
    }

    // --- SSHFP record ---

    public function testParseSshfpRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  SSHFP  1 1 bf29468c83aa5e12fb34e45da1b22a5aba7124c0
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('SSHFP', $records[0]->type);
        $this->assertStringContainsString('1 1', $records[0]->content);
    }

    // --- DS record ---

    public function testParseDsRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  DS  60485 5 1 2BB183AF5F22588179A53B0A98631FAD1A292118
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('DS', $records[0]->type);
        $this->assertStringContainsString('60485 5 1', $records[0]->content);
    }

    // --- DNSKEY multiline (master6.data) ---

    public function testParseDnskeyMultiline(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  DNSKEY  256 3 8 (
    AwEAAcTQyaIe6nt3xSPOG2L/YfwBkOVz
    HxPRA8sGd3dMFMcqoXoIRyDHnHmFPYBA
    8yYD5DL2v2V1jiM2vdV6pHHxJVM=
)
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('DNSKEY', $records[0]->type);
        $this->assertStringContainsString('256 3 8', $records[0]->content);
    }

    // --- Underscore labels (service records) ---

    public function testParseUnderscoreLabels(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
_xmpp-server._tcp  IN  SRV  5 0 5269 xmpp.example.com.
_http._tcp          IN  SRV  0 5 80   www.example.com.
_dmarc              IN  TXT  "v=DMARC1; p=reject"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(3, $result->getRecordCount());
        $this->assertEquals('_xmpp-server._tcp.example.com', $records[0]->name);
        $this->assertEquals('SRV', $records[0]->type);
        $this->assertEquals('_http._tcp.example.com', $records[1]->name);
        $this->assertEquals('_dmarc.example.com', $records[2]->name);
    }

    // --- $INCLUDE directive (should produce warning) ---

    public function testParseIncludeDirectiveWarning(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
\$INCLUDE master6.data
www  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('$INCLUDE', $result->getWarnings()[0]);
    }

    // --- Comment-only lines ---

    public function testParseCommentOnlyLines(): void
    {
        $content = <<<ZONE
; Full line comment
\$ORIGIN example.com.
; Another comment
\$TTL 3600
; Yet another
www  IN  A  192.0.2.1
; trailing comment
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertEmpty($result->getWarnings());
    }

    public function testParseSemicolonOnLineWithLeadingWhitespace(): void
    {
        // From master10.data: line with leading whitespace and just ";"
        $content = "\$ORIGIN example.com.\n\$TTL 300\n@\t300\tIN\tA\t10.0.0.1\n\t;\n";

        $result = $this->parser->parse($content);

        $this->assertEquals(1, $result->getRecordCount());
    }

    // --- Mixed absolute and relative names in same file ---

    public function testParseMixedAbsoluteAndRelativeNames(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www                   IN  A  192.0.2.1
mail.example.com.     IN  A  192.0.2.2
ftp                   IN  A  192.0.2.3
ns1.example.com.      IN  A  192.0.2.4
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('www.example.com', $records[0]->name);
        $this->assertEquals('mail.example.com', $records[1]->name);
        $this->assertEquals('ftp.example.com', $records[2]->name);
        $this->assertEquals('ns1.example.com', $records[3]->name);
    }

    // --- PTR records ---

    public function testParsePtrRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN 2.0.192.in-addr.arpa.
\$TTL 3600
1  IN  PTR  host1.example.com.
2  IN  PTR  host2.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(2, $result->getRecordCount());
        $this->assertEquals('PTR', $records[0]->type);
        $this->assertEquals('1.2.0.192.in-addr.arpa', $records[0]->name);
        $this->assertEquals('host1.example.com', $records[0]->content);
    }

    // --- NS records with relative targets ---

    public function testParseNsRecordRelativeTarget(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@   IN  NS  ns1
@   IN  NS  ns2
ns1 IN  A   192.0.2.1
ns2 IN  A   192.0.2.2
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('NS', $records[0]->type);
        $this->assertEquals('ns1.example.com', $records[0]->content);
        $this->assertEquals('NS', $records[1]->type);
        $this->assertEquals('ns2.example.com', $records[1]->content);
    }

    // --- Delegation with NS + DNAME (good1.db) ---

    public function testParseNsAndDnameAtSameName(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 600
ns-and-dname  IN  NS     ns.ns-and-dname.example.com.
              IN  DNAME  example.org.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(2, $result->getRecordCount());
        $this->assertEquals('NS', $records[0]->type);
        $this->assertEquals('DNAME', $records[1]->type);
        $this->assertEquals('ns-and-dname.example.com', $records[0]->name);
        $this->assertEquals('ns-and-dname.example.com', $records[1]->name);
    }

    // --- HINFO record with quoted strings (example2.db) ---

    public function testParseHinfoRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
hinfo01  IN  HINFO  "Generic PC clone" "NetBSD-1.4"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('HINFO', $records[0]->type);
        $this->assertStringContainsString('Generic PC clone', $records[0]->content);
        $this->assertStringContainsString('NetBSD-1.4', $records[0]->content);
    }

    // --- RP record (example2.db) ---

    public function testParseRpRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
rp01  IN  RP  mbox-dname.example.com. txt-dname.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('RP', $records[0]->type);
        $this->assertStringContainsString('mbox-dname.example.com', $records[0]->content);
    }

    // --- SOA with inline multiline and comments (master1.data style) ---

    public function testParseSoaWithTabsAndInlineComments(): void
    {
        $content = "\$ORIGIN test.\n\$TTL 1000\n@\t\tin\tsoa\tlocalhost. postmaster.localhost. (\n\t\t\t\t1993050801\t;serial\n\t\t\t\t3600\t\t;refresh\n\t\t\t\t1800\t\t;retry\n\t\t\t\t604800\t\t;expiration\n\t\t\t\t3600 )\t\t;minimum\n";

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $soaFound = false;
        foreach ($records as $r) {
            if ($r->type === 'SOA') {
                $soaFound = true;
                $this->assertStringContainsString('localhost', $r->content);
                $this->assertStringContainsString('1993050801', $r->content);
            }
        }
        $this->assertTrue($soaFound);
    }

    // --- SOA serial with leading zeros (master11.data) ---

    public function testParseSoaSerialWithLeadingZeros(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
@  300  IN  SOA  ns. hostmaster. 00090000 1200 3600 604800 300
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('SOA', $records[0]->type);
        // Serial should be preserved as-is (not interpreted as octal)
        $this->assertStringContainsString('00090000', $records[0]->content);
    }

    // --- Comprehensive zone file (example2.db style) ---

    public function testParseComprehensiveZoneFile(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 300
@            IN  SOA   ns1.example.com. admin.example.com. 2 300 300 1814400 3600
@            IN  NS    ns1.example.com.
@            IN  NS    ns2.example.com.
ns1          IN  A     10.53.0.2
ns2          IN  A     10.53.0.3
ns2          IN  AAAA  fd92:7065:b8e:ffff::3
@            IN  A     10.0.0.2
\$TTL 3600
a01          IN  A     0.0.0.0
a02          IN  A     255.255.255.255
ipv6         IN  AAAA  ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
\$TTL 300
www          IN  CNAME example.com.
mail         IN  MX    10 mail.example.com.
             IN  TXT   "one"
             IN  TXT   "three"
             IN  A     73.80.65.49
_sip._tcp    IN  SRV   10 60 5060 sip.example.com.
@            IN  CAA   0 issue "letsencrypt.org"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        // Verify we parsed a meaningful number of records
        $this->assertGreaterThan(10, $result->getRecordCount());

        // Verify different record types are present
        $types = array_unique(array_map(fn($r) => $r->type, $records));
        $this->assertContains('SOA', $types);
        $this->assertContains('NS', $types);
        $this->assertContains('A', $types);
        $this->assertContains('AAAA', $types);
        $this->assertContains('CNAME', $types);
        $this->assertContains('MX', $types);
        $this->assertContains('TXT', $types);
        $this->assertContains('SRV', $types);
        $this->assertContains('CAA', $types);

        // TTL changes should be reflected
        $a01 = null;
        $www = null;
        foreach ($records as $r) {
            if ($r->name === 'a01.example.com') {
                $a01 = $r;
            }
            if ($r->name === 'www.example.com') {
                $www = $r;
            }
        }
        $this->assertNotNull($a01);
        $this->assertEquals(3600, $a01->ttl);
        $this->assertNotNull($www);
        $this->assertEquals(300, $www->ttl);
    }

    // --- CRLF line endings ---

    public function testParseCrlfLineEndings(): void
    {
        $content = "\$ORIGIN example.com.\r\n\$TTL 3600\r\nwww  IN  A  192.0.2.1\r\nmail IN  A  192.0.2.2\r\n";

        $result = $this->parser->parse($content);

        $this->assertEquals(2, $result->getRecordCount());
        $records = $result->getRecords();
        $this->assertEquals('www.example.com', $records[0]->name);
        $this->assertEquals('mail.example.com', $records[1]->name);
    }

    // --- SPF record type ---

    public function testParseSpfRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  SPF  "v=spf1 -all"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('SPF', $records[0]->type);
        $this->assertStringContainsString('v=spf1', $records[0]->content);
    }

    // --- CAA with multiple parameters ---

    public function testParseCaaWithAccountUri(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  CAA  0 issue "letsencrypt.org; accounturi=https://acme-v02.api.letsencrypt.org/acme/acct/12345"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('CAA', $records[0]->type);
        $this->assertStringContainsString('0 issue', $records[0]->content);
        $this->assertStringContainsString('accounturi=', $records[0]->content);
    }

    // --- CERT record multiline (example2.db) ---

    public function testParseCertRecordMultiline(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
cert01  IN  CERT  65534 65535 PRIVATEOID (
    MxFcby9k/yvedMfQgKzhH5er0Mu/vILz45Ikskcef
    WCn/GxHhai6VAuHAoNUz4YoU1tVfSCSqQYn6//11U6
    d80jEeC8aTrO+KKmCaY=
)
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('CERT', $records[0]->type);
        $this->assertStringContainsString('65534 65535', $records[0]->content);
    }

    // --- Inherited owner across multiple name blocks ---

    public function testParseInheritedOwnerAcrossBlocks(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
host1  IN  A     192.0.2.1
       IN  AAAA  2001:db8::1
       IN  MX    10 mail.example.com.
host2  IN  A     192.0.2.2
       IN  AAAA  2001:db8::2
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(5, $result->getRecordCount());

        // host1 records
        $this->assertEquals('host1.example.com', $records[0]->name);
        $this->assertEquals('host1.example.com', $records[1]->name);
        $this->assertEquals('host1.example.com', $records[2]->name);

        // host2 records
        $this->assertEquals('host2.example.com', $records[3]->name);
        $this->assertEquals('host2.example.com', $records[4]->name);
    }

    // --- Uppercase TTL suffixes (BIND9 supports H, M, D, W, S) ---

    public function testParseTtlUppercaseSuffix(): void
    {
        $this->assertEquals(3600, $this->parser->parseTtl('1H'));
        $this->assertEquals(86400, $this->parser->parseTtl('1D'));
        $this->assertEquals(604800, $this->parser->parseTtl('1W'));
        $this->assertEquals(300, $this->parser->parseTtl('5M'));
        $this->assertEquals(60, $this->parser->parseTtl('60S'));
    }

    // --- Compound TTL from BIND9 SOA (8H 2H 4W 1D from mx.db) ---

    public function testParseTtlCompoundUppercase(): void
    {
        $this->assertEquals(90000, $this->parser->parseTtl('1D1H'));
        $this->assertEquals(9000, $this->parser->parseTtl('2H30M'));
        $this->assertEquals(788400, $this->parser->parseTtl('1W2D3H'));
    }

    // --- SOA without $TTL (master4.data pattern) ---

    public function testParseSoaWithoutTtlDirective(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
@  IN  SOA  ns1.example.com. admin.example.com. 2024010101 3600 900 1209600 86400
a  IN  A    192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertGreaterThanOrEqual(2, $result->getRecordCount());
        // Default TTL 86400 should apply
        $this->assertEquals(86400, $result->getDefaultTtl());
    }

    // --- Fully qualified names without $ORIGIN ---

    public function testParseFullyQualifiedNamesWithoutOrigin(): void
    {
        $content = <<<ZONE
\$TTL 3600
example.com.      IN  NS   ns1.example.com.
example.com.      IN  NS   ns2.example.com.
ns1.example.com.  IN  A    192.0.2.1
ns2.example.com.  IN  A    192.0.2.2
www.example.com.  IN  A    192.0.2.3
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals(5, $result->getRecordCount());
        $this->assertEquals('example.com', $records[0]->name);
        $this->assertEquals('ns1.example.com', $records[0]->content);
        $this->assertEquals('ns1.example.com', $records[2]->name);
        $this->assertEquals('www.example.com', $records[4]->name);
    }

    // --- Long TXT record with multiple quoted strings ---

    public function testParseLongTxtMultipleStrings(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  TXT  "v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQ" "KBgQC7ZEJzBTjYhagRHkpbU4LHH8XOcfFoSOSRFIzakVPn4MH0MRG" "MAAAAA=="
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('TXT', $records[0]->type);
        $this->assertStringContainsString('DKIM1', $records[0]->content);
        $this->assertStringContainsString('MAAAAA==', $records[0]->content);
    }

    // --- SVCB / HTTPS records (good-svcb.db) ---

    public function testParseSvcbRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 600
svcb0  IN  SVCB  0 example.net.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('SVCB', $records[0]->type);
        $this->assertStringContainsString('0 example.net', $records[0]->content);
    }

    public function testParseHttpsRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 600
@  IN  HTTPS  1 . alpn=h3
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('HTTPS', $records[0]->type);
        $this->assertStringContainsString('1 . alpn=h3', $records[0]->content);
    }

    // --- Reverse zone (in-addr.arpa) ---

    public function testParseReverseZone(): void
    {
        $content = <<<ZONE
\$ORIGIN 168.192.in-addr.arpa.
\$TTL 86400
@    IN  SOA  ns1.example.com. admin.example.com. 2024010101 3600 900 1209600 86400
@    IN  NS   ns1.example.com.
1.1  IN  PTR  router.example.com.
2.1  IN  PTR  server.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('168.192.in-addr.arpa', $result->getOrigin());

        $ptrRecords = array_filter($records, fn($r) => $r->type === 'PTR');
        $ptrRecords = array_values($ptrRecords);
        $this->assertEquals(2, count($ptrRecords));
        $this->assertEquals('1.1.168.192.in-addr.arpa', $ptrRecords[0]->name);
        $this->assertEquals('router.example.com', $ptrRecords[0]->content);
    }

    // --- IPv6 reverse zone ---

    public function testParseIpv6ReverseZone(): void
    {
        $content = <<<ZONE
\$ORIGIN 8.b.d.0.1.0.0.2.ip6.arpa.
\$TTL 86400
1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0  IN  PTR  host1.example.com.
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('PTR', $records[0]->type);
        $this->assertEquals('host1.example.com', $records[0]->content);
    }

    // --- Only whitespace and comments (no records) ---

    public function testParseOnlyWhitespaceAndComments(): void
    {
        $content = <<<ZONE
; This is a zone file with no records
; Just comments


; Another comment
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals(0, $result->getRecordCount());
        $this->assertEmpty($result->getWarnings());
    }

    // --- $ORIGIN without trailing dot ---

    public function testParseOriginWithoutTrailingDot(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com
\$TTL 3600
www  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals('example.com', $result->getOrigin());
        $records = $result->getRecords();
        $this->assertEquals('www.example.com', $records[0]->name);
    }

    // --- Record immediately after $ORIGIN without blank line ---

    public function testParseRecordImmediatelyAfterOrigin(): void
    {
        $content = "\$ORIGIN example.com.\n\$TTL 3600\nwww IN A 192.0.2.1\nmail IN A 192.0.2.2\n";

        $result = $this->parser->parse($content);

        $this->assertEquals(2, $result->getRecordCount());
    }

    // --- Large number of records ---

    public function testParseManyRecords(): void
    {
        $lines = ["\$ORIGIN example.com.", "\$TTL 3600"];
        for ($i = 1; $i <= 100; $i++) {
            $lines[] = sprintf("host%d  IN  A  10.0.0.%d", $i, $i % 256);
        }
        $content = implode("\n", $lines);

        $result = $this->parser->parse($content);

        $this->assertEquals(100, $result->getRecordCount());
        $this->assertEmpty($result->getWarnings());
    }

    // --- URI record ---

    public function testParseUriRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
_http._tcp  IN  URI  10 1 "https://www.example.com/path"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('URI', $records[0]->type);
        $this->assertStringContainsString('10 1', $records[0]->content);
    }

    // --- NSEC record ---

    public function testParseNsecRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
alfa  IN  NSEC  host.example.com. A MX RRSIG NSEC
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('NSEC', $records[0]->type);
        $this->assertStringContainsString('host.example.com', $records[0]->content);
    }

    // --- NSEC3 record ---

    public function testParseNsec3Record(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
0p9mhaveqvm6t7vbl5lop2u3t2rp3tom  IN  NSEC3  1 1 12 aabbccdd CPNMU MX DNSKEY NS SOA NSEC3PARAM RRSIG
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('NSEC3', $records[0]->type);
        $this->assertStringContainsString('1 1 12', $records[0]->content);
    }

    // --- NSEC3PARAM record ---

    public function testParseNsec3paramRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  NSEC3PARAM  1 0 12 aabbccdd
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('NSEC3PARAM', $records[0]->type);
        $this->assertEquals('1 0 12 aabbccdd', $records[0]->content);
    }

    // --- LOC record (example2.db) ---

    public function testParseLocRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
loc01  IN  LOC  60 9 0.000 N 24 39 0.000 E 10.00m 20m 2000m 20m
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('LOC', $records[0]->type);
        $this->assertStringContainsString('60 9 0.000 N', $records[0]->content);
    }

    // --- ZONEMD record ---

    public function testParseZonemdRecord(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@  IN  ZONEMD  2024010100 1 1 aabbccdd
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('ZONEMD', $records[0]->type);
    }

    // --- EUI48 record ---

    public function testParseEui48Record(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
host  IN  EUI48  00-00-5e-00-53-2a
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('EUI48', $records[0]->type);
        $this->assertEquals('00-00-5e-00-53-2a', $records[0]->content);
    }

    // --- EUI64 record ---

    public function testParseEui64Record(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
host  IN  EUI64  00-00-5e-ef-10-00-00-2a
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('EUI64', $records[0]->type);
        $this->assertEquals('00-00-5e-ef-10-00-00-2a', $records[0]->content);
    }

    // --- Multiple $GENERATE directives produce multiple warnings ---

    public function testParseMultipleGenerateDirectivesProduceWarnings(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
\$GENERATE 11-18 all\$ A 192.0.2.8
\$GENERATE 1-2 @ PTR SERVER\$.EXAMPLE.
www  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);

        $this->assertEquals(1, $result->getRecordCount());
        $this->assertCount(2, $result->getWarnings());
        $this->assertStringContainsString('$GENERATE', $result->getWarnings()[0]);
        $this->assertStringContainsString('$GENERATE', $result->getWarnings()[1]);
    }

    // --- Very long domain name ---

    public function testParseLongDomainName(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
very-long-subdomain-name-that-is-quite-lengthy  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('very-long-subdomain-name-that-is-quite-lengthy.example.com', $records[0]->name);
    }

    // --- Record with all fields explicit (name TTL class TYPE RDATA) ---

    public function testParseRecordAllFieldsExplicit(): void
    {
        $content = <<<ZONE
\$ORIGIN example.com.
\$TTL 86400
www.example.com.  300  IN  A  192.0.2.1
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        $this->assertEquals('www.example.com', $records[0]->name);
        $this->assertEquals(300, $records[0]->ttl);
        $this->assertEquals('A', $records[0]->type);
        $this->assertEquals('192.0.2.1', $records[0]->content);
    }

    // --- Cloudflare export format (no $ORIGIN, FQDNs, TTL=1) ---

    public function testParseCloudflareExportFormat(): void
    {
        $content = <<<ZONE
;;
;; Domain:     example.com.
;; Exported:   2024-01-01 00:00:00
;;
;; A Records
a.example.com.  1  IN  A  104.21.0.1
b.example.com.  1  IN  A  172.67.0.1
;; CNAME Records
www.example.com.  1  IN  CNAME  example.com.
;; MX Records
example.com.  1  IN  MX  10 mail.example.com.
;; TXT Records
example.com.  1  IN  TXT  "v=spf1 include:_spf.google.com ~all"
ZONE;

        $result = $this->parser->parse($content);
        $records = $result->getRecords();

        // All TTL=1 should be mapped to auto value (300)
        foreach ($records as $record) {
            $this->assertEquals(300, $record->ttl, "TTL not mapped for {$record->name}");
        }

        // Verify origin inferred from FQDNs
        $this->assertEquals('example.com', $result->getOrigin());

        $this->assertEquals(5, $result->getRecordCount());
        $types = array_map(fn($r) => $r->type, $records);
        $this->assertContains('A', $types);
        $this->assertContains('CNAME', $types);
        $this->assertContains('MX', $types);
        $this->assertContains('TXT', $types);
    }
}
