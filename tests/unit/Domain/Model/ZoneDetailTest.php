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

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneDetail;

/**
 * The mapper both zone repositories share: driver-typed row values become typed
 * properties, and toArray() reproduces the column-keyed shape getZone() used to return.
 */
#[CoversClass(ZoneDetail::class)]
class ZoneDetailTest extends TestCase
{
    public function testFromRowTypesDriverValuesAndDerivesTheUtf8Name(): void
    {
        $zone = ZoneDetail::fromRow(
            ['id' => '7', 'name' => 'xn--bcher-kva.example', 'type' => 'NATIVE', 'master' => '', 'account' => null,
                'owner' => '5', 'comment' => null, 'record_count' => '3', 'secured' => '1'],
            [['username' => 'alice', 'fullname' => 'Alice A'], ['username' => 'bob', 'fullname' => null]]
        );

        $this->assertSame(7, $zone->id);
        $this->assertSame('xn--bcher-kva.example', $zone->name);
        $this->assertSame('bücher.example', $zone->utf8Name);
        $this->assertSame('', $zone->master);
        $this->assertNull($zone->account);
        $this->assertSame(5, $zone->owner);
        $this->assertSame('', $zone->comment);
        $this->assertSame(3, $zone->recordCount);
        $this->assertTrue($zone->secured);
        $this->assertSame(['alice', 'bob'], $zone->owners);
        $this->assertSame(['Alice A', null], $zone->fullNames);
        $this->assertSame('alice', $zone->primaryOwner());
    }

    public function testToArrayReproducesTheLegacyKeysInOrder(): void
    {
        $zone = ZoneDetail::fromRow(
            ['id' => 1, 'name' => 'signed.example', 'type' => 'MASTER', 'master' => null, 'account' => 'ops',
                'owner' => 5, 'comment' => 'signed zone', 'record_count' => 3, 'secured' => 1],
            [['username' => 'bob', 'fullname' => null], ['username' => 'alice', 'fullname' => 'Alice A']]
        );

        // fullname keeps the raw null of the primary owner while full_names blanks it
        $this->assertSame([
            'id' => 1,
            'name' => 'signed.example',
            'type' => 'MASTER',
            'master' => null,
            'account' => 'ops',
            'owner' => 5,
            'comment' => 'signed zone',
            'record_count' => 3,
            'secured' => true,
            'count_records' => 3,
            'username' => 'bob',
            'fullname' => null,
            'utf8_name' => 'signed.example',
            'owners' => ['bob', 'alice'],
            'full_names' => ['', 'Alice A'],
            'users' => ['bob', 'alice'],
        ], $zone->toArray());
    }

    public function testUnownedZoneHasNoPrimaryOwner(): void
    {
        $zone = ZoneDetail::fromRow(
            ['id' => 2, 'name' => 'plain.example', 'type' => 'NATIVE', 'master' => null, 'account' => '',
                'owner' => 0, 'comment' => '', 'record_count' => 1, 'secured' => 0],
            []
        );

        $this->assertNull($zone->primaryOwner());
        $this->assertNull($zone['username']);
        $this->assertNull($zone['fullname']);
        $this->assertSame([], $zone['owners']);
        $this->assertSame([], $zone['full_names']);
        $this->assertSame([], $zone['users']);
    }

    public function testArrayAccessServesTheLegacyKeysReadOnly(): void
    {
        $zone = ZoneDetail::fromRow(
            ['id' => 1, 'name' => 'signed.example', 'type' => 'MASTER', 'master' => null, 'account' => 'ops',
                'owner' => 5, 'comment' => '', 'record_count' => 3, 'secured' => 1],
            [['username' => 'alice', 'fullname' => 'Alice A']]
        );

        $this->assertTrue(isset($zone['name']));
        $this->assertTrue(isset($zone['utf8_name']));
        $this->assertFalse(isset($zone['utf8Name']));
        $this->assertSame('signed.example', $zone['name']);
        $this->assertSame(3, $zone['count_records']);
        $this->assertSame(['alice'], $zone['users']);

        $this->expectException(LogicException::class);
        $zone['name'] = 'other.example';
    }
}
