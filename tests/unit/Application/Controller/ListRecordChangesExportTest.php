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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\ListRecordChangesController;
use ReflectionClass;
use ReflectionMethod;

/**
 * The CSV export writes its header once and then streams rows, so the two must stay
 * in step. A field added to the row without the matching header shifts every later
 * column under the wrong heading.
 */
#[CoversClass(ListRecordChangesController::class)]
class ListRecordChangesExportTest extends TestCase
{
    private function buildExportRow(array $log): array
    {
        $controller = (new ReflectionClass(ListRecordChangesController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ListRecordChangesController::class, 'buildExportRow');
        $method->setAccessible(true);
        return $method->invoke($controller, $log);
    }

    public function testExportRowExposesTheChangesetReason(): void
    {
        $row = $this->buildExportRow([
            'created_at' => '2026-01-01 10:00:00',
            'action' => 'record_create',
            'username' => 'alice',
            'zone_id' => 10,
            'changeset_id' => 3,
            'changeset_comment' => 'move web tier',
        ]);

        $this->assertSame(3, $row['changeset_id']);
        $this->assertSame('move web tier', $row['change_reason']);
    }

    public function testEveryRowHasTheSameShapeSoTheHeaderCannotDrift(): void
    {
        // The header is derived from an empty row; a row built from real data must
        // therefore carry exactly the same keys, in the same order.
        $header = array_keys($this->buildExportRow([]));
        $populated = array_keys($this->buildExportRow([
            'created_at' => '2026-01-01 10:00:00',
            'action' => 'record_delete',
            'username' => 'bob',
            'user_id' => 2,
            'zone_id' => 11,
            'changeset_id' => 4,
            'changeset_comment' => 'decommission',
            'record_id' => 99,
            'client_ip' => '192.0.2.7',
            'before_state' => '{}',
            'after_state' => null,
        ]));

        $this->assertSame($header, $populated);
    }

    public function testUngroupedChangeExportsNullReasonRatherThanOmittingTheColumn(): void
    {
        $row = $this->buildExportRow(['action' => 'record_create', 'username' => 'alice']);

        $this->assertArrayHasKey('changeset_id', $row);
        $this->assertArrayHasKey('change_reason', $row);
        $this->assertNull($row['changeset_id']);
        $this->assertNull($row['change_reason']);
    }
}
