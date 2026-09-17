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

namespace Poweradmin\Tests\Unit\Application\Http;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\ZoneEditIntent;

/**
 * Characterizes the zone editor's POST dispatch exactly as EditController
 * historically sniffed it, including the max_input_vars truncation fallback
 * and the form_complete-only save that bumps the SOA serial.
 */
class ZoneEditIntentTest extends TestCase
{
    private const ADD_FIELDS = ['name' => 'www', 'content' => '192.0.2.1', 'type' => 'A'];

    public function testGetRequestIsNoIntent(): void
    {
        $this->assertSame(ZoneEditIntent::NONE, ZoneEditIntent::from(false, self::ADD_FIELDS + ['commit' => '1']));
    }

    public function testCommitWithAddFieldsAloneIsAnAddRecord(): void
    {
        $this->assertSame(
            ZoneEditIntent::ADD_RECORD,
            ZoneEditIntent::from(true, self::ADD_FIELDS + ['commit' => 'Save'])
        );
    }

    public function testCommitWithAddFieldsAndRecordRowsIsAFullSave(): void
    {
        $this->assertSame(
            ZoneEditIntent::SAVE_RECORDS,
            ZoneEditIntent::from(true, self::ADD_FIELDS + ['commit' => 'Save', 'record' => [['name' => 'a']]])
        );
    }

    public function testCommitWithRecordRowsOnlyIsASave(): void
    {
        $this->assertSame(
            ZoneEditIntent::SAVE_RECORDS,
            ZoneEditIntent::from(true, ['commit' => 'Save', 'record' => [['name' => 'a']]])
        );
    }

    public function testCommitWithZoneCommentOnlyIsASave(): void
    {
        $this->assertSame(
            ZoneEditIntent::SAVE_RECORDS,
            ZoneEditIntent::from(true, ['commit' => 'Save', 'zone_comment' => 'hello'])
        );
    }

    public function testCommitWithOnlyTheFormCompleteMarkerStillSaves(): void
    {
        // The client omits unchanged rows but always sends form_complete, so an
        // unchanged form still bumps the SOA serial
        $this->assertSame(
            ZoneEditIntent::SAVE_RECORDS,
            ZoneEditIntent::from(true, ['commit' => 'Save', 'form_complete' => '1'])
        );
    }

    public function testBareCommitDoesNothing(): void
    {
        $this->assertSame(ZoneEditIntent::NONE, ZoneEditIntent::from(true, ['commit' => 'Save']));
    }

    public function testRecordRowsWithoutCommitIsTheTruncatedSave(): void
    {
        // max_input_vars dropped the trailing fields including the submit button
        $this->assertSame(
            ZoneEditIntent::SAVE_TRUNCATED,
            ZoneEditIntent::from(true, ['record' => [['name' => 'a']]])
        );
    }

    public function testTruncatedPostWithAddFieldsIsStillTheTruncatedSave(): void
    {
        $this->assertSame(
            ZoneEditIntent::SAVE_TRUNCATED,
            ZoneEditIntent::from(true, self::ADD_FIELDS + ['record' => [['name' => 'a']]])
        );
    }

    public function testPostWithNeitherCommitNorRecordsDoesNothing(): void
    {
        $this->assertSame(ZoneEditIntent::NONE, ZoneEditIntent::from(true, ['zone_comment' => 'x']));
    }

    public function testAddFieldsRequireAllThreeKeys(): void
    {
        $this->assertTrue(ZoneEditIntent::hasAddRecordFields(self::ADD_FIELDS));
        $this->assertFalse(ZoneEditIntent::hasAddRecordFields(['name' => 'www', 'content' => 'x']));
        $this->assertFalse(ZoneEditIntent::hasAddRecordFields(['name' => 'www', 'type' => 'A']));
        $this->assertFalse(ZoneEditIntent::hasAddRecordFields([]));
    }

    public function testEmptyStringsStillCountAsPresentFields(): void
    {
        // The historical checks were !== null, not truthiness: an empty add form
        // submitted with commit is an ADD_RECORD that then fails validation
        $this->assertSame(
            ZoneEditIntent::ADD_RECORD,
            ZoneEditIntent::from(true, ['commit' => '', 'name' => '', 'content' => '', 'type' => ''])
        );
    }
}
