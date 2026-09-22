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
use Poweradmin\Domain\Enum\ZoneSoaHealth;
use Poweradmin\Domain\Model\ZoneSummary;

/**
 * The mapper every zone list shares: driver-typed row values become typed
 * properties, optional columns stay optional, and toArray() reproduces the
 * column-keyed row the lists used to return.
 */
#[CoversClass(ZoneSummary::class)]
class ZoneSummaryTest extends TestCase
{
    public function testFromRowTypesDriverValuesAndDerivesTheUtf8Name(): void
    {
        $zone = ZoneSummary::fromRow([
            'id' => '7',
            'name' => 'xn--bcher-kva.example',
            'type' => 'NATIVE',
            'count_records' => '3',
            'is_disabled' => false,
            'is_missing_soa' => true,
            'soa_health' => 'soa_missing',
            'comment' => null,
            'secured' => '1',
            'owners' => ['alice', 'bob'],
            'full_names' => ['Alice A', ''],
            'serial' => 2024010101,
            'template' => '',
        ]);

        $this->assertSame(7, $zone->id);
        $this->assertSame('xn--bcher-kva.example', $zone->name);
        $this->assertSame('bücher.example', $zone->utf8Name);
        $this->assertSame('NATIVE', $zone->type);
        $this->assertSame(3, $zone->recordCount);
        $this->assertSame(ZoneSoaHealth::SOA_MISSING, $zone->soaHealth);
        $this->assertSame('', $zone->comment);
        $this->assertTrue($zone->secured);
        $this->assertSame(['alice', 'bob'], $zone->owners);
        $this->assertSame(['Alice A', ''], $zone->fullNames);
        $this->assertSame('alice', $zone->primaryOwner());
        $this->assertSame('2024010101', $zone->serial);
        $this->assertSame('', $zone->template);
        $this->assertNull($zone->signedSerial);
        $this->assertNull($zone->notifiedSerial);
        $this->assertNull($zone->canonicalId);
    }

    public function testToArrayEmitsOnlyTheColumnsTheListingCarried(): void
    {
        $bare = ZoneSummary::fromRow(['id' => 2, 'name' => 'plain.example', 'type' => 'NATIVE', 'count_records' => 1, 'secured' => 0]);

        $this->assertSame([
            'id' => 2,
            'name' => 'plain.example',
            'utf8_name' => 'plain.example',
            'type' => 'NATIVE',
            'count_records' => 1,
            'comment' => '',
            'secured' => false,
            'owners' => [],
            'full_names' => [],
            'users' => [],
        ], $bare->toArray());
        $this->assertNull($bare->primaryOwner());
        $this->assertFalse(isset($bare['serial']));
        $this->assertFalse(isset($bare['notified_serial']));
    }

    public function testToArrayReproducesEveryOptionalColumnInOrder(): void
    {
        $zone = ZoneSummary::fromRow([
            'id' => 7,
            'canonical_id' => 7,
            'name' => 'signed.example',
            'utf8_name' => 'signed.example',
            'type' => 'MASTER',
            'count_records' => 3,
            'is_disabled' => false,
            'is_missing_soa' => false,
            'soa_health' => 'ok',
            'comment' => 'signed zone',
            'secured' => true,
            'owners' => ['alice'],
            'full_names' => ['Alice A'],
            'users' => ['alice'],
            'serial' => '2024010101',
            'signed_serial' => '2024010105',
            'template' => 'Basic',
            'notified_serial' => 0,
            'notify_pending' => true,
        ]);

        $this->assertSame([
            'id' => 7,
            'canonical_id' => 7,
            'name' => 'signed.example',
            'utf8_name' => 'signed.example',
            'type' => 'MASTER',
            'count_records' => 3,
            'is_disabled' => false,
            'is_missing_soa' => false,
            'soa_health' => 'ok',
            'comment' => 'signed zone',
            'secured' => true,
            'owners' => ['alice'],
            'full_names' => ['Alice A'],
            'users' => ['alice'],
            'serial' => '2024010101',
            'signed_serial' => '2024010105',
            'template' => 'Basic',
            'notified_serial' => 0,
            'notify_pending' => true,
        ], $zone->toArray());
        $this->assertSame(json_encode($zone->toArray()), json_encode($zone));
    }

    public function testArrayAccessServesTheLegacyKeysReadOnly(): void
    {
        $zone = ZoneSummary::fromRow([
            'id' => 1, 'name' => 'signed.example', 'type' => 'MASTER', 'count_records' => 3, 'secured' => 1,
            'owners' => ['alice'], 'full_names' => ['Alice A'], 'notified_serial' => 5, 'notify_pending' => false,
        ]);

        $this->assertTrue(isset($zone['name']));
        $this->assertTrue(isset($zone['utf8_name']));
        $this->assertTrue(isset($zone['notified_serial']));
        $this->assertFalse(isset($zone['utf8Name']));
        $this->assertFalse(isset($zone['template']));
        $this->assertSame('signed.example', $zone['name']);
        $this->assertSame(3, $zone['count_records']);
        $this->assertSame(['alice'], $zone['users']);
        $this->assertFalse($zone['notify_pending']);
        $this->assertNull($zone['template']);

        $this->expectException(LogicException::class);
        $zone['name'] = 'other.example';
    }

    public function testOffsetUnsetIsRefused(): void
    {
        $zone = ZoneSummary::fromRow(['id' => 1, 'name' => 'a.example']);

        $this->expectException(LogicException::class);
        unset($zone['name']);
    }
}
