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


namespace unit;

use PDOStatement;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Creating a zone inside an existing zone is refused unless the caller owns that
 * zone or may edit other users' zones.
 *
 * Separate processes keep verify_permission()'s static cache from leaking.
 */
#[RunTestsInSeparateProcesses]
class DnsRecordForeignParentZoneTest extends TestCase
{
    private const CORP_ID = 5;
    private const ENG_CORP_ID = 6;

    public function testParentOwnedByAnotherUserIsForeign(): void
    {
        $dnsRecord = $this->dnsRecord(permissions: [], zones: ['example.com' => self::CORP_ID], ownedIds: []);

        $this->assertTrue($dnsRecord->is_subzone_of_foreign_zone('sub.example.com'));
    }

    public function testParentOwnedByTheCallerIsNotForeign(): void
    {
        $dnsRecord = $this->dnsRecord(permissions: [], zones: ['example.com' => self::CORP_ID], ownedIds: [self::CORP_ID]);

        $this->assertFalse($dnsRecord->is_subzone_of_foreign_zone('sub.example.com'));
    }

    public function testOwnedClosestParentDecidesOverAForeignFartherOne(): void
    {
        // Admin owns corp, the caller owns eng.corp: lab.eng.corp splits only the caller's zone
        $dnsRecord = $this->dnsRecord(
            permissions: [],
            zones: ['corp' => self::CORP_ID, 'eng.corp' => self::ENG_CORP_ID],
            ownedIds: [self::ENG_CORP_ID]
        );

        $this->assertFalse($dnsRecord->is_subzone_of_foreign_zone('lab.eng.corp'));
    }

    public function testForeignClosestParentIsRefusedEvenWhenAFartherOneIsOwned(): void
    {
        $dnsRecord = $this->dnsRecord(
            permissions: [],
            zones: ['corp' => self::CORP_ID, 'eng.corp' => self::ENG_CORP_ID],
            ownedIds: [self::CORP_ID]
        );

        $this->assertTrue($dnsRecord->is_subzone_of_foreign_zone('lab.eng.corp'));
    }

    public function testNoExistingParentIsNotForeign(): void
    {
        $dnsRecord = $this->dnsRecord(permissions: [], zones: [], ownedIds: []);

        $this->assertFalse($dnsRecord->is_subzone_of_foreign_zone('sub.example.com'));
    }

    public function testUnownedClosestParentDoesNotBlock(): void
    {
        $dnsRecord = $this->dnsRecord(permissions: [], zones: ['example.com' => self::CORP_ID], ownedIds: [], ownerlessIds: [self::CORP_ID]);

        $this->assertFalse($dnsRecord->is_subzone_of_foreign_zone('sub.example.com'));
    }

    public function testUnownedClosestParentDecidesOverAForeignFartherOne(): void
    {
        $dnsRecord = $this->dnsRecord(
            permissions: [],
            zones: ['corp' => self::CORP_ID, 'eng.corp' => self::ENG_CORP_ID],
            ownedIds: [],
            ownerlessIds: [self::ENG_CORP_ID]
        );

        $this->assertFalse($dnsRecord->is_subzone_of_foreign_zone('lab.eng.corp'));
    }

    public function testZoneContentEditOthersIsExempt(): void
    {
        $dnsRecord = $this->dnsRecord(permissions: ['zone_content_edit_others' => [[]]], zones: ['example.com' => self::CORP_ID], ownedIds: []);

        $this->assertFalse($dnsRecord->is_subzone_of_foreign_zone('sub.example.com'));
    }

    public function testSuperuserIsExempt(): void
    {
        $dnsRecord = $this->dnsRecord(permissions: ['user_is_ueberuser' => [[]]], zones: ['example.com' => self::CORP_ID], ownedIds: []);

        $this->assertFalse($dnsRecord->is_subzone_of_foreign_zone('sub.example.com'));
    }

    /**
     * @param array<string, int> $zones Existing zone names and their ids
     * @param int[] $ownedIds Zone ids the caller owns
     * @param int[] $ownerlessIds Zone ids nobody owns; every other zone has an owner
     */
    private function dnsRecord(array $permissions, array $zones, array $ownedIds, array $ownerlessIds = []): DnsRecord
    {
        $_SESSION['userid'] = 7;

        $permissionRows = $this->createMock(PDOStatement::class);
        $permissionRows->method('fetchAll')->willReturn($permissions);

        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value): string => (string)(int)$value);
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($permissionRows, $zones, $ownerlessIds): PDOStatement {
            if (str_contains($sql, 'perm_items')) {
                return $permissionRows;
            }
            $name = null;
            if (str_contains($sql, 'FROM zones')) {
                $ownerCount = $this->createMock(PDOStatement::class);
                $ownerCount->method('bindValue')->willReturnCallback(function (string $param, mixed $value) use (&$name): bool {
                    $name = $value;
                    return true;
                });
                $ownerCount->method('fetchColumn')->willReturnCallback(
                    function () use (&$name, $ownerlessIds): int {
                        return in_array($name, $ownerlessIds, true) ? 0 : 1;
                    }
                );
                return $ownerCount;
            }
            $lookup = $this->createMock(PDOStatement::class);
            $lookup->method('bindValue')->willReturnCallback(function (string $param, mixed $value) use (&$name): bool {
                $name = $value;
                return true;
            });
            $lookup->method('fetch')->willReturnCallback(
                function () use (&$name, $zones): array|false {
                    return isset($zones[$name]) ? ['id' => $zones[$name]] : false;
                }
            );
            return $lookup;
        });
        $db->method('queryOne')->willReturnCallback(function (string $sql) use ($ownedIds): mixed {
            foreach ($ownedIds as $id) {
                if (str_ends_with(trim($sql), 'zones.domain_id = ' . $id)) {
                    return $id;
                }
            }
            return false;
        });

        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturnCallback(
            fn(string $name, mixed $default = null): mixed => $name === 'dns_parent_zone_ownership_check' ? true : null
        );

        return new DnsRecord($db, $config);
    }
}
