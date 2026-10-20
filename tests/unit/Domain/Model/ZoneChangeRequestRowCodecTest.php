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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneChangeRequestRowCodec;
use Poweradmin\Domain\Service\Zone\ZoneEditRow;

/**
 * The stored action shapes are a data contract: the keys and types written by
 * earlier versions must keep decoding.
 */
class ZoneChangeRequestRowCodecTest extends TestCase
{
    public function testTheAfterStateKeepsItsKeysAndTypes(): void
    {
        $after = ZoneChangeRequestRowCodec::afterState(['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => '300', 'prio' => '0', 'disabled' => 1]);

        $this->assertSame(['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => 300, 'prio' => 0, 'disabled' => 1, 'comment' => ''], $after);
    }

    public function testTheSnapshotKeepsItsKeysAndTypes(): void
    {
        $before = ZoneChangeRequestRowCodec::snapshot(['id' => 5, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => '3600', 'prio' => '0', 'disabled' => '0'], 'example.com');

        $this->assertSame(['id' => '5', 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => false, 'comment' => null, 'zone_name' => 'example.com'], $before);
        $this->assertNull(ZoneChangeRequestRowCodec::snapshot([], 'example.com')['id']);
    }

    public function testAStoredAfterStateReplaysAsAnEditorRow(): void
    {
        $row = ZoneChangeRequestRowCodec::rowFromAfter('enc-id', ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => 300, 'prio' => 0, 'disabled' => 1, 'comment' => 'why']);

        $this->assertEquals(new ZoneEditRow('enc-id', 'www.example.com', 'A', '192.0.2.9', 300, 0, true, 'why'), $row);
    }

    public function testAnOldRequestWithoutOptionalKeysStillReplays(): void
    {
        $row = ZoneChangeRequestRowCodec::rowFromAfter(5, ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.9']);

        $this->assertEquals(new ZoneEditRow(5, 'www.example.com', 'A', '192.0.2.9', 0, 0, false, ''), $row);
    }
}
