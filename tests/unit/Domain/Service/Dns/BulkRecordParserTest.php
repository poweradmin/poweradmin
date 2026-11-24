<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\BulkRecordParser;

class BulkRecordParserTest extends TestCase
{
    private BulkRecordParser $parser;
    private int $defaultTtl = 3600;

    protected function setUp(): void
    {
        $this->parser = new BulkRecordParser();
    }

    public function testParseARecord(): void
    {
        $result = $this->parser->parseLine('www,A,192.168.1.1', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('www', $result['name']);
        $this->assertSame('A', $result['type']);
        $this->assertSame('192.168.1.1', $result['content']);
        $this->assertSame(0, $result['prio']);
        $this->assertSame(3600, $result['ttl']);
        $this->assertSame('', $result['comment']);
    }

    public function testParseARecordWithAllFields(): void
    {
        $result = $this->parser->parseLine('www,A,192.168.1.1,0,300,web server', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('192.168.1.1', $result['content']);
        $this->assertSame(0, $result['prio']);
        $this->assertSame(300, $result['ttl']);
        $this->assertSame('web server', $result['comment']);
    }

    public function testParseMxRecord(): void
    {
        $result = $this->parser->parseLine('@,MX,mail.example.com.,10,3600', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('@', $result['name']);
        $this->assertSame('MX', $result['type']);
        $this->assertSame('mail.example.com.', $result['content']);
        $this->assertSame(10, $result['prio']);
        $this->assertSame(3600, $result['ttl']);
    }

    public function testParseSrvRecordCsvExportFormat(): void
    {
        $result = $this->parser->parseLine('_sip._tcp,SRV,"0 5060 sip.example.com.",0,3600', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('_sip._tcp', $result['name']);
        $this->assertSame('SRV', $result['type']);
        $this->assertSame('0 5060 sip.example.com.', $result['content']);
        $this->assertSame(0, $result['prio']);
        $this->assertSame(3600, $result['ttl']);
    }

    public function testParseSrvRecordCsvExportFormatWithComment(): void
    {
        $result = $this->parser->parseLine('_sip._tcp,SRV,"0 5060 sip.example.com.",0,3600,SIP service', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('0 5060 sip.example.com.', $result['content']);
        $this->assertSame(0, $result['prio']);
        $this->assertSame(3600, $result['ttl']);
        $this->assertSame('SIP service', $result['comment']);
    }

    public function testParseSrvRecordLegacyFormat(): void
    {
        $result = $this->parser->parseLine('_sip._tcp,SRV,sip.example.com.,0,5060,3600', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('_sip._tcp', $result['name']);
        $this->assertSame('SRV', $result['type']);
        $this->assertSame('0 5060 sip.example.com.', $result['content']);
        $this->assertSame(0, $result['prio']);
        $this->assertSame(3600, $result['ttl']);
    }

    public function testParseSrvRecordLegacyFormatWithComment(): void
    {
        $result = $this->parser->parseLine('_sip._tcp,SRV,sip.example.com.,0,5060,3600,SIP service', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('0 5060 sip.example.com.', $result['content']);
        $this->assertSame(3600, $result['ttl']);
        $this->assertSame('SIP service', $result['comment']);
    }

    public function testParseSrvRecordLegacyFormatDefaultTtl(): void
    {
        $result = $this->parser->parseLine('_sip._tcp,SRV,sip.example.com.,0,5060', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('0 5060 sip.example.com.', $result['content']);
        $this->assertSame(3600, $result['ttl']);
    }

    public function testParseSrvRecordCsvExportFormatDefaultTtl(): void
    {
        $result = $this->parser->parseLine('_sip._tcp,SRV,"0 5060 sip.example.com.",0', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('0 5060 sip.example.com.', $result['content']);
        $this->assertSame(3600, $result['ttl']);
    }

    public function testBothSrvFormatsProduceSameContent(): void
    {
        $csvFormat = $this->parser->parseLine('_sip._tcp,SRV,"0 5060 sip.example.com.",0,3600', $this->defaultTtl);
        $legacyFormat = $this->parser->parseLine('_sip._tcp,SRV,sip.example.com.,0,5060,3600', $this->defaultTtl);

        $this->assertIsArray($csvFormat);
        $this->assertIsArray($legacyFormat);
        $this->assertSame($csvFormat['content'], $legacyFormat['content']);
        $this->assertSame($csvFormat['ttl'], $legacyFormat['ttl']);
    }

    public function testInvalidSrvRecordTooFewFields(): void
    {
        $result = $this->parser->parseLine('_sip._tcp,SRV,sip.example.com.,0', $this->defaultTtl);

        $this->assertSame('Invalid SRV format. Use: name,SRV,"weight port target",priority,ttl or name,SRV,target,weight,port,ttl', $result);
    }

    public function testEmptyLineReturnsError(): void
    {
        $result = $this->parser->parseLine('', $this->defaultTtl);
        $this->assertSame('Empty line.', $result);
    }

    public function testTooFewFieldsReturnsError(): void
    {
        $result = $this->parser->parseLine('www,A', $this->defaultTtl);
        $this->assertSame('Invalid format. Expected at least: name,type,content', $result);
    }

    public function testTypeCaseInsensitive(): void
    {
        $result = $this->parser->parseLine('www,a,192.168.1.1', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('A', $result['type']);
    }

    public function testDefaultPriorityAndTtl(): void
    {
        $result = $this->parser->parseLine('www,A,192.168.1.1', 7200);

        $this->assertIsArray($result);
        $this->assertSame(0, $result['prio']);
        $this->assertSame(7200, $result['ttl']);
    }

    public function testParseDisabledFieldYes(): void
    {
        $result = $this->parser->parseLine('www,A,192.168.1.1,0,300,Yes', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['disabled']);
        $this->assertSame('', $result['comment']);
    }

    public function testParseDisabledFieldNo(): void
    {
        $result = $this->parser->parseLine('www,A,192.168.1.1,0,300,No', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame(0, $result['disabled']);
        $this->assertSame('', $result['comment']);
    }

    public function testParseDisabledFieldWithComment(): void
    {
        $result = $this->parser->parseLine('www,A,192.168.1.1,0,300,Yes,web server', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['disabled']);
        $this->assertSame('web server', $result['comment']);
    }

    public function testParseWithoutDisabledField(): void
    {
        $result = $this->parser->parseLine('www,A,192.168.1.1,0,300,web server', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame(0, $result['disabled']);
        $this->assertSame('web server', $result['comment']);
    }

    public function testParseSrvCsvExportWithDisabled(): void
    {
        $result = $this->parser->parseLine('_sip._tcp,SRV,"0 5060 sip.example.com.",0,3600,Yes', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['disabled']);
        $this->assertSame('', $result['comment']);
    }

    public function testDefaultDisabledIsZero(): void
    {
        $result = $this->parser->parseLine('www,A,192.168.1.1', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame(0, $result['disabled']);
    }

    public function testWhitespaceIsTrimmed(): void
    {
        $result = $this->parser->parseLine('  www , A , 192.168.1.1 ', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('www', $result['name']);
        $this->assertSame('A', $result['type']);
        $this->assertSame('192.168.1.1', $result['content']);
    }

    public function testTxtWithEscapedQuotesRoundTripsThroughTheCsvExportFormat(): void
    {
        $content = '"say \\"hi\\""';
        $stream = fopen('php://memory', 'w+');
        fputcsv($stream, ['www.example.com', 'TXT', $content, 0, 3600], ',', '"', '');
        rewind($stream);
        $line = rtrim((string)stream_get_contents($stream), "\n");
        fclose($stream);

        $parsed = (new BulkRecordParser())->parseLine($line);

        $this->assertIsArray($parsed);
        $this->assertSame($content, $parsed['content']);
        $this->assertSame(3600, $parsed['ttl']);
    }

    public function testSplitRecordsKeepsNewlineInsideQuotedComment(): void
    {
        $text = "a,A,1.1.1.1,0,300,No,\"x\nwww,A,6.6.6.6\"\nb,A,2.2.2.2";

        $records = $this->parser->splitRecords($text);

        $this->assertCount(2, $records);
        $parsed = $this->parser->parseLine($records[0]);
        $this->assertIsArray($parsed);
        $this->assertSame("x\nwww,A,6.6.6.6", $parsed['comment']);
        $this->assertSame('b,A,2.2.2.2', $records[1]);
    }

    public function testSplitRecordsPlainInputSplitsPerLine(): void
    {
        $this->assertSame(['a,A,1.1.1.1', 'b,A,2.2.2.2', ''], $this->parser->splitRecords("a,A,1.1.1.1\nb,A,2.2.2.2\n"));
    }

    public function testSplitRecordsStrayQuoteMidFieldDoesNotJoinLines(): void
    {
        $this->assertSame(['a,TXT,say "hi', 'b,A,2.2.2.2'], $this->parser->splitRecords("a,TXT,say \"hi\nb,A,2.2.2.2"));
    }

    public function testSplitRecordsUnterminatedQuoteFallsBackToLines(): void
    {
        $this->assertSame(['a,TXT,"open', 'b,A,2.2.2.2'], $this->parser->splitRecords("a,TXT,\"open\nb,A,2.2.2.2"));
    }

    public function testADoubledQuoteInsideAQuotedCommentKeepsTheCellOpen(): void
    {
        $rows = $this->parser->splitRecords("a,A,1.1.1.1,0,300,No,\"x \"\"q\"\" y\nz\"\nb,A,2.2.2.2");

        $this->assertSame(['a,A,1.1.1.1,0,300,No,"x ""q"" y' . "\n" . 'z"', 'b,A,2.2.2.2'], $rows);
    }

    public function testAMissingClosingQuoteDoesNotSwallowTheNextLine(): void
    {
        $rows = $this->parser->splitRecords("@,TXT,\"v=spf1 -all\n_dmarc,TXT,\"v=DMARC1; p=reject\"");

        $this->assertSame(['@,TXT,"v=spf1 -all', '_dmarc,TXT,"v=DMARC1; p=reject"'], $rows);
    }
}
