<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordRow;
use Poweradmin\Domain\Service\Dns\BindZoneFileGenerator;

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

    public function testGenerateEmptyRecordsGivesNoFile(): void
    {
        $this->assertSame('', $this->generator->generate('example.com', []));
    }

    /**
     * The zone export and the change-request snapshot both feed this the zone
     * listing, which hands over read models rather than rows.
     */
    public function testAReadModelFromTheListingIsRendered(): void
    {
        $records = [
            RecordRow::fromRow(['id' => 1, 'name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com hostmaster.example.com 1 10800 3600 604800 3600', 'ttl' => 3600]),
            RecordRow::fromRow(['id' => 2, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 300]),
            RecordRow::fromRow(['id' => 3, 'name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'prio' => 10]),
        ];

        $output = (new BindZoneFileGenerator())->generate('example.com', $records);

        $this->assertStringContainsString('$ORIGIN example.com.', $output);
        $this->assertStringContainsString('www.example.com.', $output);
        $this->assertStringContainsString('192.0.2.1', $output);
        $this->assertStringContainsString('10 mail.example.com.', $output);
    }

    public function testCommentsAreLeftOutUnlessAsked(): void
    {
        $records = [
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'comment' => 'web server'],
        ];
        $withoutComment = [
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0],
        ];

        $output = $this->generator->generate('example.com', $records);

        $this->assertStringNotContainsString('poweradmin-comment', $output);
        $this->assertSame(
            $this->withoutTimestamp($this->generator->generate('example.com', $withoutComment)),
            $this->withoutTimestamp($output)
        );
    }

    public function testCommentIsWrittenDirectlyAboveItsRecord(): void
    {
        $records = [
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'comment' => 'web server'],
            ['name' => 'api.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 3600, 'prio' => 0, 'comment' => null],
            ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'prio' => 10, 'comment' => '  '],
        ];

        $output = $this->generator->generate('example.com', $records, true);

        $this->assertStringContainsString("; poweradmin-comment: web server\nwww.example.com. 3600 IN A 192.0.2.1\napi.example.com. 3600 IN A 192.0.2.2\n", $output);
        $this->assertSame(1, substr_count($output, 'poweradmin-comment'));
    }

    public function testCommentLinesCannotInjectRecords(): void
    {
        $records = [
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'comment' => "first\nevil 300 IN A 198.51.100.66\r\n\rlast\rline"],
        ];

        $output = $this->generator->generate('example.com', $records, true);

        $this->assertStringContainsString(
            "; poweradmin-comment: first\n; poweradmin-comment: evil 300 IN A 198.51.100.66\n; poweradmin-comment:\n; poweradmin-comment: last\n; poweradmin-comment: line\nwww.example.com. 3600 IN A 192.0.2.1\n",
            $output
        );
        $this->assertStringNotContainsString("\r", $output);
        $recordLines = array_filter(explode("\n", $output), fn(string $line): bool => $line !== '' && $line[0] !== ';' && $line[0] !== '$');
        $this->assertSame(['www.example.com. 3600 IN A 192.0.2.1'], array_values($recordLines));
    }

    public function testDisabledRecordsAreLeftOut(): void
    {
        $records = [
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'comment' => 'live'],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 3600, 'prio' => 0, 'disabled' => 1, 'comment' => 'off'],
            ['name' => 'old.example.com', 'type' => 'CNAME', 'content' => 'www.example.com', 'ttl' => 3600, 'prio' => 0, 'disabled' => true],
            ['name' => 'pg.example.com', 'type' => 'A', 'content' => '192.0.2.3', 'ttl' => 3600, 'prio' => 0, 'disabled' => 't'],
            ['name' => 'pg-live.example.com', 'type' => 'A', 'content' => '192.0.2.4', 'ttl' => 3600, 'prio' => 0, 'disabled' => 'f'],
        ];

        foreach ([false, true] as $withComments) {
            $output = $this->generator->generate('example.com', $records, $withComments);

            $this->assertStringContainsString('www.example.com. 3600 IN A 192.0.2.1', $output);
            $this->assertStringContainsString('pg-live.example.com. 3600 IN A 192.0.2.4', $output);
            $this->assertStringNotContainsString('192.0.2.2', $output);
            $this->assertStringNotContainsString('192.0.2.3', $output);
            $this->assertStringNotContainsString('CNAME', $output);
            $this->assertStringNotContainsString('off', $output);
        }
    }

    public function testDisabledRecordRowsAreLeftOut(): void
    {
        $row = static fn(string $content, bool $disabled): RecordRow => RecordRow::fromRow([
            'id' => 1, 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => $content,
            'ttl' => 3600, 'prio' => 0, 'disabled' => $disabled,
        ]);

        $output = $this->generator->generate('example.com', [$row('192.0.2.1', false), $row('192.0.2.2', true)]);

        $this->assertStringContainsString('192.0.2.1', $output);
        $this->assertStringNotContainsString('192.0.2.2', $output);
    }

    public function testZoneWithOnlyDisabledRecordsGivesNoFile(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com admin.example.com 1 3600 900 1209600 86400', 'ttl' => 86400, 'prio' => 0, 'disabled' => true],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 1],
        ];

        $this->assertSame('', $this->generator->generate('example.com', $records));
        $this->assertSame('', $this->generator->generate('example.com', $records, true));
    }

    public function testSnapshotOptionWritesDisabledRecordsAsMarkedLinesInPlace(): void
    {
        $records = [
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ['name' => 'off.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 300, 'prio' => 0, 'disabled' => 1],
            ['name' => 'api.example.com', 'type' => 'A', 'content' => '192.0.2.3', 'ttl' => 3600, 'prio' => 0, 'disabled' => 'f'],
            ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'prio' => 10, 'disabled' => 't'],
        ];

        $output = $this->generator->generate('example.com', $records, false, true);

        $this->assertStringContainsString(
            "; A Records\nwww.example.com. 3600 IN A 192.0.2.1\n; poweradmin-disabled: off.example.com. 300 IN A 192.0.2.2\napi.example.com. 3600 IN A 192.0.2.3\n",
            $output
        );
        $this->assertStringContainsString("; MX Records\n; poweradmin-disabled: example.com. 3600 IN MX 10 mail.example.com.\n", $output);
    }

    public function testSnapshotOptionLeavesAZoneWithoutDisabledRecordsUnchanged(): void
    {
        $records = [
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'comment' => 'web'],
        ];

        $this->assertSame(
            $this->withoutTimestamp($this->generator->generate('example.com', $records)),
            $this->withoutTimestamp($this->generator->generate('example.com', $records, false, true))
        );
        $this->assertSame(
            $this->withoutTimestamp($this->generator->generate('example.com', $records, true)),
            $this->withoutTimestamp($this->generator->generate('example.com', $records, true, true))
        );
    }

    public function testSnapshotOptionWritesTheZoneWhenEveryRecordIsDisabled(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com admin.example.com 1 3600 900 1209600 86400', 'ttl' => 7200, 'prio' => 0, 'disabled' => true],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 1],
        ];

        $output = $this->generator->generate('example.com', $records, false, true);

        $this->assertStringContainsString("\$ORIGIN example.com.\n\$TTL 7200\n", $output);
        $this->assertStringContainsString('; poweradmin-disabled: example.com. 7200 IN SOA ns1.example.com. admin.example.com. 1 3600 900 1209600 86400', $output);
        $this->assertStringContainsString('; poweradmin-disabled: www.example.com. 3600 IN A 192.0.2.1', $output);
        $this->assertSame('', $this->generator->generate('example.com', [], false, true));
    }

    public function testSnapshotOptionMarksDisabledRecordRows(): void
    {
        $row = static fn(string $content, bool $disabled): RecordRow => RecordRow::fromRow([
            'id' => 1, 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => $content,
            'ttl' => 3600, 'prio' => 0, 'disabled' => $disabled,
        ]);

        $output = $this->generator->generate('example.com', [$row('192.0.2.1', false), $row('192.0.2.2', true)], false, true);

        $this->assertStringContainsString("www.example.com. 3600 IN A 192.0.2.1\n; poweradmin-disabled: www.example.com. 3600 IN A 192.0.2.2\n", $output);
    }

    public function testCommentOfADisabledRecordSitsDirectlyAboveItsMarkerLine(): void
    {
        $records = [
            ['name' => 'off.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 300, 'prio' => 0, 'disabled' => 1, 'comment' => 'parked'],
        ];

        $output = $this->generator->generate('example.com', $records, true, true);

        $this->assertStringContainsString("; poweradmin-comment: parked\n; poweradmin-disabled: off.example.com. 300 IN A 192.0.2.2\n", $output);
    }

    private function withoutTimestamp(string $output): string
    {
        return preg_replace('/^;; Exported: .*$/m', '', $output);
    }
}
