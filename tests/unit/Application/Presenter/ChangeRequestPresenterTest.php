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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\ChangeRequestPresenter;
use Poweradmin\Domain\Model\ZoneChangeRequest;

#[CoversClass(ChangeRequestPresenter::class)]
class ChangeRequestPresenterTest extends TestCase
{
    public function testChangedFieldsIgnoreRepresentationDifferences(): void
    {
        $before = ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => false, 'comment' => null];
        $after = ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => '3600', 'prio' => '0', 'disabled' => 0, 'comment' => ''];

        $this->assertSame([], ChangeRequestPresenter::changedFields($before, $after));
        $this->assertSame(['content', 'disabled'], ChangeRequestPresenter::changedFields($before, ['content' => '192.0.2.2', 'disabled' => 1] + $after));
        $this->assertSame([], ChangeRequestPresenter::changedFields(null, $after));
    }

    public function testActionsCarryTheStaleFlagByIndex(): void
    {
        $request = new ZoneChangeRequest(1, 2, 'example.com', ZoneChangeRequest::KIND_RECORDS, ZoneChangeRequest::STATUS_PENDING, 3, 'bob', null, null, [
            ['op' => ZoneChangeRequest::OP_ADD, 'after' => ['name' => 'a.example.com', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => 60, 'prio' => 0, 'disabled' => 0, 'comment' => '']],
            ['op' => ZoneChangeRequest::OP_DELETE, 'record_id' => '4', 'before' => ['name' => 'b.example.com', 'type' => 'A', 'content' => '192.0.2.8']],
        ], null, null, null, null, '2026-01-01 00:00:00', null, null, null);

        $rows = ChangeRequestPresenter::actions($request, [1]);

        $this->assertSame('add', $rows[0]['op']);
        $this->assertFalse($rows[0]['stale']);
        $this->assertNull($rows[0]['before']);
        $this->assertSame('delete', $rows[1]['op']);
        $this->assertTrue($rows[1]['stale']);
        $this->assertNull($rows[1]['after']);
        $this->assertSame(2, ChangeRequestPresenter::summary($request)['action_count']);
    }

    public function testAgeRoundsDownToTheLargestUnit(): void
    {
        $now = strtotime('2026-01-02 12:00:00');

        $this->assertSame('just now', ChangeRequestPresenter::age('2026-01-02 11:59:30', $now));
        $this->assertSame('5 min ago', ChangeRequestPresenter::age('2026-01-02 11:54:59', $now));
        $this->assertSame('3 h ago', ChangeRequestPresenter::age('2026-01-02 08:30:00', $now));
        $this->assertSame('1 d ago', ChangeRequestPresenter::age('2026-01-01 10:00:00', $now));
        $this->assertSame('garbage', ChangeRequestPresenter::age('garbage', $now));
    }
}
