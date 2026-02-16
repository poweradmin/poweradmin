<?php

namespace Tests\Unit\Module\ZoneImportExport\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Module\ZoneImportExport\Service\BindZoneFileGenerator;

class BindZoneFileGeneratorTest extends TestCase
{
    private BindZoneFileGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BindZoneFileGenerator();
    }

    public function testGenerateHeader(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com admin.example.com 2024010101 3600 900 1209600 86400', 'ttl' => 86400, 'prio' => 0],
        ];

        $output = $this->generator->generate('example.com', $records);

        $this->assertStringContainsString(';; Zone: example.com.', $output);
        $this->assertStringContainsString(';; Exported:', $output);
        $this->assertStringContainsString('$ORIGIN example.com.', $output);
        $this->assertStringContainsString('$TTL 86400', $output);
    }

    public function testGenerateARecord(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com admin.example.com 2024010101 3600 900 1209600 86400', 'ttl' => 86400, 'prio' => 0],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0],
        ];

        $output = $this->generator->generate('example.com', $records);

        $this->assertStringContainsString('www.example.com. 3600 IN A 192.0.2.1', $output);
    }

    public function testGenerateMxRecord(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'prio' => 10],
        ];

        $output = $this->generator->generate('example.com', $records);

        $this->assertStringContainsString('example.com. 3600 IN MX 10 mail.example.com.', $output);
    }

    public function testGenerateNsRecord(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.example.com', 'ttl' => 86400, 'prio' => 0],
        ];

        $output = $this->generator->generate('example.com', $records);

        $this->assertStringContainsString('example.com. 86400 IN NS ns1.example.com.', $output);
    }

    public function testGenerateCnameRecord(): void
    {
        $records = [
            ['name' => 'www.example.com', 'type' => 'CNAME', 'content' => 'example.com', 'ttl' => 3600, 'prio' => 0],
        ];

        $output = $this->generator->generate('example.com', $records);

        $this->assertStringContainsString('www.example.com. 3600 IN CNAME example.com.', $output);
    }

    public function testGenerateSoaWithTrailingDots(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com admin.example.com 2024010101 3600 900 1209600 86400', 'ttl' => 86400, 'prio' => 0],
        ];

        $output = $this->generator->generate('example.com', $records);

        $this->assertStringContainsString('ns1.example.com.', $output);
        $this->assertStringContainsString('admin.example.com.', $output);
    }

    public function testGenerateSrvRecord(): void
    {
        $records = [
            ['name' => '_sip._tcp.example.com', 'type' => 'SRV', 'content' => '60 5060 sip.example.com', 'ttl' => 3600, 'prio' => 10],
        ];

        $output = $this->generator->generate('example.com', $records);

        $this->assertStringContainsString('_sip._tcp.example.com. 3600 IN SRV 10 60 5060 sip.example.com.', $output);
    }

    public function testGenerateRecordTypeGrouping(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com admin.example.com 2024010101 3600 900 1209600 86400', 'ttl' => 86400, 'prio' => 0],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0],
            ['name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.example.com', 'ttl' => 86400, 'prio' => 0],
        ];

        $output = $this->generator->generate('example.com', $records);

        // SOA should come before NS which should come before A
        $soaPos = strpos($output, '; SOA Records');
        $nsPos = strpos($output, '; NS Records');
        $aPos = strpos($output, '; A Records');

        $this->assertLessThan($nsPos, $soaPos);
        $this->assertLessThan($aPos, $nsPos);
    }

    public function testGenerateTxtRecord(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'TXT', 'content' => '"v=spf1 include:_spf.google.com ~all"', 'ttl' => 3600, 'prio' => 0],
        ];

        $output = $this->generator->generate('example.com', $records);

        $this->assertStringContainsString('TXT "v=spf1', $output);
    }

    public function testGenerateEmptyRecords(): void
    {
        $output = $this->generator->generate('example.com', []);

        $this->assertStringContainsString('$ORIGIN example.com.', $output);
        $this->assertStringContainsString('$TTL 86400', $output);
    }
}
