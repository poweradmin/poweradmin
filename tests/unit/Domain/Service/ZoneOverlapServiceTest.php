<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ZoneOverlapService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

#[CoversClass(ZoneOverlapService::class)]
class ZoneOverlapServiceTest extends TestCase
{
    private const USER_ID = 2;

    /**
     * @param array<array{id:int,name:string}> $ancestorRows zones matched by the ancestor IN lookup
     * @param array<array{id:int,name:string}> $descendantRows zones matched by the descendant LIKE lookup
     * @param list<int> $ownedZoneIds domain ids the user owns
     */
    private function makeService(
        array $ancestorRows = [],
        array $descendantRows = [],
        array $ownedZoneIds = [],
        bool $isAdmin = false,
        bool $checkEnabled = true
    ): ZoneOverlapService {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            function (string $group, string $key, $default = null) use ($checkEnabled) {
                if ($group === 'dns' && $key === 'parent_zone_ownership_check') {
                    return $checkEnabled;
                }
                if ($group === 'database' && $key === 'pdns_db_name') {
                    return null;
                }
                return $default;
            }
        );

        $permission = $this->createMock(ApiPermissionService::class);
        $permission->method('userHasPermission')->willReturn($isAdmin);
        $permission->method('userOwnsZone')->willReturnCallback(
            fn(int $userId, int $zoneId): bool => in_array($zoneId, $ownedZoneIds, true)
        );

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($ancestorRows, $descendantRows) {
            if (str_contains($sql, ' IN (')) {
                return $this->statementReturning($ancestorRows);
            }
            if (str_contains($sql, 'LIKE')) {
                return $this->statementReturning($descendantRows);
            }
            return $this->statementReturning([]);
        });

        return new ZoneOverlapService($db, $config, $permission);
    }

    /**
     * @param array<array{id:int,name:string}> $rows
     */
    private function statementReturning(array $rows): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
        return $stmt;
    }

    public function testBlocksChildZoneUnderParentOwnedByAnother(): void
    {
        $service = $this->makeService(ancestorRows: [['id' => 14, 'name' => 'a.com']]);

        $this->assertSame('a.com', $service->findConflictingZone('b.a.com', self::USER_ID));
    }

    public function testAllowsChildZoneUnderParentOwnedBySelf(): void
    {
        $service = $this->makeService(
            ancestorRows: [['id' => 14, 'name' => 'a.com']],
            ownedZoneIds: [14]
        );

        $this->assertNull($service->findConflictingZone('b.a.com', self::USER_ID));
    }

    public function testAllowsZoneWithNoOverlap(): void
    {
        $service = $this->makeService();

        $this->assertNull($service->findConflictingZone('standalone.com', self::USER_ID));
    }

    public function testClosestParentDecides(): void
    {
        // Both ancestors exist; the more-specific one owned by another user wins.
        $service = $this->makeService(
            ancestorRows: [['id' => 99, 'name' => 'com'], ['id' => 14, 'name' => 'a.com']],
            ownedZoneIds: [99]
        );

        $this->assertSame('a.com', $service->findConflictingZone('b.a.com', self::USER_ID));
    }

    public function testAncestorMatchIsCaseInsensitive(): void
    {
        // A case-insensitive collation can return a mixed-case row for the
        // lowercased lookup; it must still be detected.
        $service = $this->makeService(ancestorRows: [['id' => 14, 'name' => 'A.CoM']]);

        $this->assertSame('a.com', $service->findConflictingZone('b.a.com', self::USER_ID));
    }

    public function testBlocksParentZoneOverChildOwnedByAnother(): void
    {
        $service = $this->makeService(descendantRows: [['id' => 15, 'name' => 'b.a.com']]);

        $this->assertSame('b.a.com', $service->findConflictingZone('a.com', self::USER_ID));
    }

    public function testAllowsParentZoneOverOwnChild(): void
    {
        $service = $this->makeService(
            descendantRows: [['id' => 15, 'name' => 'b.a.com']],
            ownedZoneIds: [15]
        );

        $this->assertNull($service->findConflictingZone('a.com', self::USER_ID));
    }

    public function testCoversReverseZones(): void
    {
        // A /24 nested under a /16 owned by another user is blocked too.
        $service = $this->makeService(ancestorRows: [['id' => 20, 'name' => '10.in-addr.arpa']]);

        $this->assertSame('10.in-addr.arpa', $service->findConflictingZone('1.10.in-addr.arpa', self::USER_ID));
    }

    public function testUeberuserBypassesCheck(): void
    {
        $service = $this->makeService(
            ancestorRows: [['id' => 14, 'name' => 'a.com']],
            isAdmin: true
        );

        $this->assertNull($service->findConflictingZone('b.a.com', self::USER_ID));
    }

    public function testDisabledCheckAllowsEverything(): void
    {
        $service = $this->makeService(
            ancestorRows: [['id' => 14, 'name' => 'a.com']],
            checkEnabled: false
        );

        $this->assertNull($service->findConflictingZone('b.a.com', self::USER_ID));
    }
}
