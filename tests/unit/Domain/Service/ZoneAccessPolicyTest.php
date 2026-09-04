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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ZoneAccessPolicy;

#[CoversClass(ZoneAccessPolicy::class)]
class ZoneAccessPolicyTest extends TestCase
{
    public static function canEditZoneProvider(): array
    {
        return [
            'all ignores ownership' => ['all', false, true],
            'own requires ownership' => ['own', true, true],
            'own without ownership' => ['own', false, false],
            'own_as_client requires ownership' => ['own_as_client', true, true],
            'own_as_client without ownership' => ['own_as_client', false, false],
            'none never edits' => ['none', true, false],
        ];
    }

    #[DataProvider('canEditZoneProvider')]
    public function testCanEditZone(string $permEdit, bool $owner, bool $expected): void
    {
        $this->assertSame($expected, ZoneAccessPolicy::canEditZone($permEdit, $owner));
    }

    public static function nsRecordLockedProvider(): array
    {
        return [
            'non-NS record never NS-locked' => ['A', 'own_as_client', false, 'www.example.com', 'example.com', false],
            'NS locked for own_as_client without subzone perm' => ['NS', 'own_as_client', false, 'sub.example.com', 'example.com', true],
            'apex NS locked even with subzone perm' => ['NS', 'own_as_client', true, 'example.com', 'example.com', true],
            'subzone NS unlocked with subzone perm' => ['NS', 'own_as_client', true, 'sub.example.com', 'example.com', false],
            'apex compare is case-insensitive' => ['NS', 'own_as_client', true, 'EXAMPLE.com', 'example.COM', true],
            'NS free for all editors' => ['NS', 'all', false, 'example.com', 'example.com', false],
            'NS free for plain own editors' => ['NS', 'own', false, 'example.com', 'example.com', false],
            'trailing dots ignored in apex compare' => ['NS', 'own_as_client', true, 'example.com.', 'example.com', true],
            'null zone name stays locked' => ['NS', 'own_as_client', true, 'sub.example.com', null, true],
        ];
    }

    #[DataProvider('nsRecordLockedProvider')]
    public function testIsNsRecordLocked(
        string $type,
        string $permEdit,
        bool $subzonePerm,
        string $recordName,
        ?string $zoneName,
        bool $expected
    ): void {
        $this->assertSame(
            $expected,
            ZoneAccessPolicy::isNsRecordLocked($type, $permEdit, $subzonePerm, $recordName, $zoneName)
        );
    }

    public static function recordLockedProvider(): array
    {
        return [
            'read-only zone locks everything' => [true, 'A', 'all', false, true],
            'SOA locked unless perm is all' => [false, 'SOA', 'own', false, true],
            'SOA editable with all' => [false, 'SOA', 'all', false, false],
            'NS lock propagates' => [false, 'NS', 'own_as_client', true, true],
            'plain record editable' => [false, 'A', 'own', false, false],
            'LUA locked for client-level editors' => [false, 'LUA', 'own_as_client', false, true],
            'LUA editable with own' => [false, 'LUA', 'own', false, false],
        ];
    }

    #[DataProvider('recordLockedProvider')]
    public function testIsRecordLocked(
        bool $zoneReadOnly,
        string $type,
        string $permEdit,
        bool $nsLocked,
        bool $expected
    ): void {
        $this->assertSame(
            $expected,
            ZoneAccessPolicy::isRecordLocked($zoneReadOnly, $type, $permEdit, $nsLocked)
        );
    }
}
