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

use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

class DnsRecordForeignZoneTest extends TestCase
{
    private const USER_ID = 7;

    /**
     * @param array<string, int> $zones Existing zone name => id
     * @param ?list<int> $zonesWithOwner Zone ids that have a user or group owner; null = all
     */
    private function createDnsRecord(array $zones, bool $checkEnabled = true, ?array $zonesWithOwner = null): DnsRecord
    {
        $zonesWithOwner ??= array_values($zones);
        $db = $this->createMock(PDOCommon::class);
        $db->method('prepare')->willReturnCallback(function () use ($zones, $zonesWithOwner) {
            $boundName = null;
            $boundZoneId = null;
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('bindValue')->willReturnCallback(function ($param, $value) use (&$boundName, &$boundZoneId) {
                if ($param === ':name') {
                    $boundName = $value;
                }
                if ($param === ':zone_id') {
                    $boundZoneId = $value;
                }
                return true;
            });
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchColumn')->willReturnCallback(function () use (&$boundZoneId, $zonesWithOwner) {
                return in_array($boundZoneId, $zonesWithOwner, true) ? 1 : 0;
            });
            $stmt->method('fetch')->willReturnCallback(function () use (&$boundName, $zones) {
                return isset($zones[$boundName]) ? ['id' => $zones[$boundName]] : false;
            });
            return $stmt;
        });

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) use ($checkEnabled) {
            if ($group === 'dns' && $key === 'parent_zone_ownership_check') {
                return $checkEnabled;
            }
            if ($group === 'database' && $key === 'type') {
                return 'mysql';
            }
            return $default;
        });

        return new DnsRecord($db, $config);
    }

    /**
     * @param list<string> $permissions
     * @param list<int> $ownedZoneIds
     */
    private function permissions(array $permissions, array $ownedZoneIds): ApiPermissionService
    {
        $service = $this->createMock(ApiPermissionService::class);
        $service->method('userHasPermission')->willReturnCallback(
            fn(int $userId, string $name) => in_array($name, $permissions, true)
        );
        $service->method('userOwnsZone')->willReturnCallback(
            fn(int $userId, int $zoneId) => in_array($zoneId, $ownedZoneIds, true)
        );
        return $service;
    }

    public function testRefusesZoneInsideZoneOwnedByAnotherUser(): void
    {
        $dnsRecord = $this->createDnsRecord(['example.com' => 10]);

        $this->assertTrue($dnsRecord->isInsideForeignZone('Shop.Example.com.', self::USER_ID, $this->permissions([], [])));
    }

    public function testAllowsZoneInsideOwnZone(): void
    {
        $dnsRecord = $this->createDnsRecord(['example.com' => 10]);

        $this->assertFalse($dnsRecord->isInsideForeignZone('shop.example.com', self::USER_ID, $this->permissions([], [10])));
    }

    public function testClosestOwnedParentAllowsEvenUnderAForeignZone(): void
    {
        // Admin owns corp, the user owns eng.corp: lab.eng.corp is the user's own sub-delegation.
        $dnsRecord = $this->createDnsRecord(['corp' => 10, 'eng.corp' => 11]);

        $this->assertFalse($dnsRecord->isInsideForeignZone('lab.eng.corp', self::USER_ID, $this->permissions([], [11])));
    }

    public function testClosestForeignParentRefusesEvenUnderAnOwnedZone(): void
    {
        $dnsRecord = $this->createDnsRecord(['corp' => 10, 'eng.corp' => 11]);

        $this->assertTrue($dnsRecord->isInsideForeignZone('lab.eng.corp', self::USER_ID, $this->permissions([], [10])));
    }

    public function testAllowsZoneInsideAParentNobodyOwns(): void
    {
        $dnsRecord = $this->createDnsRecord(['example.com' => 10], true, []);

        $this->assertFalse($dnsRecord->isInsideForeignZone('shop.example.com', self::USER_ID, $this->permissions([], [])));
    }

    public function testRefusesZoneInsideAParentOwnedByAnotherUserOrGroup(): void
    {
        $dnsRecord = $this->createDnsRecord(['example.com' => 10], true, [10]);

        $this->assertTrue($dnsRecord->isInsideForeignZone('shop.example.com', self::USER_ID, $this->permissions([], [])));
    }

    public function testAllowsWhenNoEnclosingZoneExists(): void
    {
        $dnsRecord = $this->createDnsRecord([]);

        $this->assertFalse($dnsRecord->isInsideForeignZone('shop.example.com', self::USER_ID, $this->permissions([], [])));
    }

    public function testSuperuserAndEditOthersAreExempt(): void
    {
        $dnsRecord = $this->createDnsRecord(['example.com' => 10]);

        $this->assertFalse($dnsRecord->isInsideForeignZone('shop.example.com', self::USER_ID, $this->permissions(['user_is_ueberuser'], [])));
        $this->assertFalse($dnsRecord->isInsideForeignZone('shop.example.com', self::USER_ID, $this->permissions(['zone_content_edit_others'], [])));
    }

    public function testSettingDisablesTheCheck(): void
    {
        $dnsRecord = $this->createDnsRecord(['example.com' => 10], false);

        $this->assertFalse($dnsRecord->isInsideForeignZone('shop.example.com', self::USER_ID, $this->permissions([], [])));
    }
}
