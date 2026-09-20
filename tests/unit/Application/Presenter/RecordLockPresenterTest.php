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

namespace Poweradmin\Tests\Unit\Application\Presenter;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\RecordLockPresenter;

/**
 * The zone editor's row decoration: display fallbacks plus which rows render
 * read-only. Pins the behaviour the edit page relied on inline.
 */
class RecordLockPresenterTest extends TestCase
{
    private function record(string $type, string $name = 'www.example.com', array $extra = []): array
    {
        return array_merge(['name' => $name, 'type' => $type, 'content' => '192.0.2.1'], $extra);
    }

    private function decorateOne(array $record, string $permEdit, bool $nsSubzone = false, bool $readOnly = false): array
    {
        $rows = RecordLockPresenter::decorate([$record], 'example.com', $permEdit, $nsSubzone, $readOnly);

        return $rows[0];
    }

    public function testFillsDisplayFieldsFromTheRecordName(): void
    {
        $row = $this->decorateOne($this->record('A'), 'all');

        $this->assertSame('www.example.com', $row['display_name']);
        $this->assertSame('www.example.com', $row['editable_name']);
        $this->assertFalse($row['unsaved_edit']);
        $this->assertSame('', $row['stored_summary']);
    }

    public function testKeepsDisplayFieldsTheCallerAlreadySet(): void
    {
        $row = $this->decorateOne(
            $this->record('A', 'www.example.com', ['display_name' => 'www', 'editable_name' => 'www']),
            'all'
        );

        $this->assertSame('www', $row['display_name']);
        $this->assertSame('www', $row['editable_name']);
    }

    public function testOrdinaryRecordIsUnlockedForAFullEditor(): void
    {
        $this->assertFalse($this->decorateOne($this->record('A'), 'all')['record_locked']);
    }

    public function testEveryRecordLocksInAReadOnlyZone(): void
    {
        $this->assertTrue($this->decorateOne($this->record('A'), 'all', false, true)['record_locked']);
    }

    public function testSoaLocksForAnyoneBelowFullEdit(): void
    {
        $this->assertFalse($this->decorateOne($this->record('SOA'), 'all')['record_locked']);
        $this->assertTrue($this->decorateOne($this->record('SOA'), 'own')['record_locked']);
        $this->assertTrue($this->decorateOne($this->record('SOA'), 'own_as_client')['record_locked']);
    }

    public function testLuaLocksForClientLevelEditors(): void
    {
        $this->assertTrue($this->decorateOne($this->record('LUA'), 'own_as_client')['record_locked']);
        $this->assertFalse($this->decorateOne($this->record('LUA'), 'own')['record_locked']);
    }

    public function testNsLocksForClientLevelEditorsWithoutTheSubzoneGrant(): void
    {
        $this->assertTrue($this->decorateOne($this->record('NS'), 'own_as_client')['record_locked']);
        $this->assertFalse($this->decorateOne($this->record('NS'), 'own')['record_locked']);
    }

    public function testSubzoneNsUnlocksForAClientEditorHoldingTheGrant(): void
    {
        $subzoneNs = $this->record('NS', 'sub.example.com');

        $this->assertFalse($this->decorateOne($subzoneNs, 'own_as_client', true)['record_locked']);
        // The apex NS stays locked even with the grant
        $this->assertTrue($this->decorateOne($this->record('NS', 'example.com'), 'own_as_client', true)['record_locked']);
    }

    public function testDecoratesEveryRowAndPreservesOrder(): void
    {
        $rows = RecordLockPresenter::decorate(
            [$this->record('A', 'a.example.com'), $this->record('SOA', 'example.com')],
            'example.com',
            'own',
            false,
            false
        );

        $this->assertCount(2, $rows);
        $this->assertSame('a.example.com', $rows[0]['name']);
        $this->assertFalse($rows[0]['record_locked']);
        $this->assertTrue($rows[1]['record_locked']);
    }

    public function testEmptyRecordSetIsReturnedUnchanged(): void
    {
        $this->assertSame([], RecordLockPresenter::decorate([], 'example.com', 'all', false, false));
    }
}
