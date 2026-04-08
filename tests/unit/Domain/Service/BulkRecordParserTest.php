<?php

namespace PoweradminTest\Domain\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\BulkRecordParser;

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

        $this->assertIsString($result);
    }

    public function testEmptyLineReturnsError(): void
    {
        $result = $this->parser->parseLine('', $this->defaultTtl);
        $this->assertIsString($result);
    }

    public function testTooFewFieldsReturnsError(): void
    {
        $result = $this->parser->parseLine('www,A', $this->defaultTtl);
        $this->assertIsString($result);
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

    public function testWhitespaceIsTrimmed(): void
    {
        $result = $this->parser->parseLine('  www , A , 192.168.1.1 ', $this->defaultTtl);

        $this->assertIsArray($result);
        $this->assertSame('www', $result['name']);
        $this->assertSame('A', $result['type']);
        $this->assertSame('192.168.1.1', $result['content']);
    }
}
