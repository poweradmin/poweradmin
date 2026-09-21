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

namespace Poweradmin\Tests\Unit\Application\Web;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Web\ZoneEditFormParser;
use Poweradmin\Domain\Service\Zone\ZoneEditRow;

/**
 * The zone editor's POST encoding: record[] rows with a trailing _complete
 * marker, "on" for a ticked disabled box, form_complete at the end of the
 * form, and the max_input_vars truncation both markers detect.
 */
class ZoneEditFormParserTest extends TestCase
{
    public function testACompletePostBecomesTypedRows(): void
    {
        $submission = ZoneEditFormParser::fromPost([
            'serial' => '2024010101',
            'changed_rows_only' => '1',
            'record' => [
                '5' => $this->row('5', 'www', '192.0.2.9') + ['disabled' => 'on', 'comment' => 'note'],
                '6' => $this->row('6', 'mail', '192.0.2.2', ttl: '', prio: '10'),
            ],
            'zone_comment' => 'about the zone',
            'form_complete' => '1',
        ], 42, 'example.com', 7, 'alice');

        $this->assertSame(42, $submission->zoneId);
        $this->assertSame('example.com', $submission->zoneName);
        $this->assertSame(7, $submission->userId);
        $this->assertSame('alice', $submission->username);
        $this->assertFalse($submission->truncated);
        $this->assertSame('2024010101', $submission->serial);
        $this->assertTrue($submission->changedRowsOnly);
        $this->assertSame('about the zone', $submission->zoneComment);
        $this->assertEquals([
            new ZoneEditRow('5', 'www', 'A', '192.0.2.9', 3600, 0, true, 'note'),
            new ZoneEditRow('6', 'mail', 'A', '192.0.2.2', 0, 10, false, null),
        ], $submission->rows);
    }

    public function testAnUntickedBoxAndAnAbsentCommentFieldStayAbsent(): void
    {
        $submission = ZoneEditFormParser::fromPost(['record' => ['5' => $this->row('5', 'www', '192.0.2.9')], 'form_complete' => '1'], 42, 'example.com', 7, 'alice');

        $this->assertFalse($submission->rows[0]->disabled);
        $this->assertNull($submission->rows[0]->comment, 'the comment column was not rendered, so the stored comment must not be compared');
    }

    public function testAPostWithoutTheRecordTableIsNotTruncated(): void
    {
        $submission = ZoneEditFormParser::fromPost(['form_complete' => '1', 'zone_comment' => 'x'], 42, 'example.com', 7, 'alice');

        $this->assertSame([], $submission->rows);
        $this->assertFalse($submission->truncated);
        $this->assertNull($submission->serial);
        $this->assertFalse($submission->changedRowsOnly);
        $this->assertSame('x', $submission->zoneComment);
    }

    public function testRowsWithoutTheMarkerAreDroppedAndFlagged(): void
    {
        $partial = $this->row('6', 'mail', '192.0.2.2');
        unset($partial['_complete'], $partial['ttl']);

        $submission = ZoneEditFormParser::fromPost([
            'record' => ['5' => $this->row('5', 'www', '192.0.2.9'), '6' => $partial, '7' => 'not a row'],
            'form_complete' => '1',
        ], 42, 'example.com', 7, 'alice');

        $this->assertTrue($submission->truncated);
        $this->assertSame(['5'], array_map(fn(ZoneEditRow $r): string => (string)$r->rid, $submission->rows));
    }

    public function testAMissingFormMarkerFlagsTruncationWhenRowsArrived(): void
    {
        $submission = ZoneEditFormParser::fromPost(['record' => ['5' => $this->row('5', 'www', '192.0.2.9')], 'zone_comment' => 'lost'], 42, 'example.com', 7, 'alice');

        $this->assertTrue($submission->truncated);
        $this->assertCount(1, $submission->rows, 'complete rows are still handed over');
        $this->assertSame('lost', $submission->zoneComment);
    }

    public function testTheArrayKeyNamesTheRecordWhenTheRidFieldIsMissing(): void
    {
        $row = $this->row('5', 'www', '192.0.2.9');
        unset($row['rid']);

        $submission = ZoneEditFormParser::fromPost(['record' => [5 => $row], 'form_complete' => '1'], 42, 'example.com', 7, 'alice');

        $this->assertSame('5', $submission->rows[0]->rid);
    }

    /** @return array<string, string> A row as the editor posts it */
    private function row(string $rid, string $name, string $content, string $ttl = '3600', string $prio = '0'): array
    {
        return ['rid' => $rid, 'zid' => '42', 'name' => $name, 'type' => 'A', 'content' => $content, 'prio' => $prio, 'ttl' => $ttl, '_complete' => '1'];
    }
}
