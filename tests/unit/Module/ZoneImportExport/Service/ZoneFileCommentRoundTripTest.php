<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Module\ZoneImportExport\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordRow;
use Poweradmin\Domain\Service\Dns\BindZoneFileGenerator;
use Poweradmin\Module\ZoneImportExport\Service\BindZoneFileParser;

/**
 * A zone exported with comments reads back with the same records and the same comment on each.
 */
#[CoversClass(BindZoneFileGenerator::class)]
#[CoversClass(BindZoneFileParser::class)]
class ZoneFileCommentRoundTripTest extends TestCase
{
    public function testExportedCommentsReadBackOntoTheSameRecords(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 3600', 'ttl' => 3600, 'prio' => 0, 'comment' => null],
            ['name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.example.com', 'ttl' => 3600, 'prio' => 0, 'comment' => 'primary'],
            ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'prio' => 10, 'comment' => "line one\nline two; with \"quotes\""],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 300, 'prio' => 0, 'comment' => '  indented, ünïcödé'],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 300, 'prio' => 0, 'comment' => 'second address'],
            ['name' => 'example.com', 'type' => 'TXT', 'content' => '"v=spf1 -all"', 'ttl' => 3600, 'prio' => 0, 'comment' => ''],
            ['name' => '_sip._tcp.example.com', 'type' => 'SRV', 'content' => '5 5060 sip.example.com', 'ttl' => 3600, 'prio' => 10, 'comment' => "evil 300 IN A 198.51.100.66\n\nafter a blank line"],
        ];

        $zoneFile = (new BindZoneFileGenerator())->generate('example.com', array_map(RecordRow::fromRow(...), $records), true);
        $parsed = (new BindZoneFileParser())->parse($zoneFile);

        $this->assertSame([], $parsed->getWarnings());
        $this->assertSame($this->summarize($records), $this->summarize(array_map(fn($r) => [
            'name' => $r->name,
            'type' => $r->type,
            'content' => $r->content,
            'prio' => $r->priority,
            'comment' => $r->comment,
        ], $parsed->getRecords())));
    }

    public function testDisabledRecordsInASnapshotReadBackAsDisabled(): void
    {
        $records = [
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 3600', 'ttl' => 3600, 'prio' => 0, 'disabled' => false],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 300, 'prio' => 0, 'disabled' => false, 'comment' => 'live'],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 300, 'prio' => 0, 'disabled' => true, 'comment' => "parked\nuntil spring"],
            ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'prio' => 10, 'disabled' => true],
            ['name' => 'example.com', 'type' => 'TXT', 'content' => '"v=spf1 -all; x"', 'ttl' => 3600, 'prio' => 0, 'disabled' => true],
        ];

        $zoneFile = (new BindZoneFileGenerator())->generate('example.com', array_map(RecordRow::fromRow(...), $records), true, true);
        $parsed = (new BindZoneFileParser())->parse($zoneFile);

        $this->assertSame([], $parsed->getWarnings());
        $this->assertSame(
            ['example.com|SOA|0', 'example.com|MX|1', 'www.example.com|A|0|live', "www.example.com|A|1|parked\nuntil spring", 'example.com|TXT|1'],
            array_map(fn($r): string => rtrim(implode('|', [$r->name, $r->type, (int)$r->disabled, $r->comment]), '|'), $parsed->getRecords())
        );
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    private function summarize(array $records): array
    {
        $rows = array_map(
            fn(array $r): string => implode('|', [$r['name'], $r['type'], $r['content'], (int)$r['prio'], (string)($r['comment'] ?? '')]),
            $records
        );
        sort($rows);
        return $rows;
    }
}
