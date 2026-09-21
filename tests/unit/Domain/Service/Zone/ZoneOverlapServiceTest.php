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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ZoneOverlapService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\PermissionServiceTestCase;

#[CoversClass(ZoneOverlapService::class)]
class ZoneOverlapServiceTest extends PermissionServiceTestCase
{
    private const USER_ID = 2;

    /** @var list<list<string>> ancestor name lists handed to the repository */
    private array $ancestorLookups = [];

    /** @var list<string> descendant suffixes handed to the repository */
    private array $descendantLookups = [];

    /**
     * @param array<string,int> $ancestorRows stored name => id for the ancestor lookup
     * @param list<array{id:int,name:string}> $descendantRows zones under the new name, in repository order
     * @param list<int> $ownedZoneIds zone ids the user owns
     */
    private function makeService(
        array $ancestorRows = [],
        array $descendantRows = [],
        array $ownedZoneIds = [],
        bool $isAdmin = false,
        bool $checkEnabled = true
    ): ZoneOverlapService {
        $this->ancestorLookups = [];
        $this->descendantLookups = [];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            function (string $group, string $key, $default = null) use ($checkEnabled) {
                if ($group === 'dns' && $key === 'parent_zone_ownership_check') {
                    return $checkEnabled;
                }
                return $default;
            }
        );

        $permission = $this->buildPermissionService(
            adminUserIds: $isAdmin ? [self::USER_ID] : [],
            ownedZonesByUser: [self::USER_ID => $ownedZoneIds]
        );

        $zones = $this->createMock(DomainRepositoryInterface::class);
        $zones->method('findZoneIdsByNames')->willReturnCallback(function (array $names) use ($ancestorRows): array {
            $this->ancestorLookups[] = $names;
            return $ancestorRows;
        });
        $zones->method('findZonesUnder')->willReturnCallback(function (string $suffix) use ($descendantRows): array {
            $this->descendantLookups[] = $suffix;
            return $descendantRows;
        });

        return new ZoneOverlapService($zones, $config, $permission);
    }

    public function testBlocksChildZoneUnderParentOwnedByAnother(): void
    {
        $service = $this->makeService(ancestorRows: ['a.com' => 14]);

        $this->assertSame('a.com', $service->findConflictingZone('b.a.com', self::USER_ID));
    }

    public function testAllowsChildZoneUnderParentOwnedBySelf(): void
    {
        $service = $this->makeService(
            ancestorRows: ['a.com' => 14],
            ownedZoneIds: [14]
        );

        $this->assertNull($service->findConflictingZone('b.a.com', self::USER_ID));
    }

    public function testAllowsZoneWithNoOverlap(): void
    {
        $service = $this->makeService();

        $this->assertNull($service->findConflictingZone('standalone.com', self::USER_ID));
    }

    public function testLooksUpEveryAncestorClosestFirstAndTheLowercasedDescendantSuffix(): void
    {
        $service = $this->makeService();

        $service->findConflictingZone('C.B.A.com.', self::USER_ID);

        $this->assertSame([['b.a.com', 'a.com', 'com']], $this->ancestorLookups);
        $this->assertSame(['c.b.a.com'], $this->descendantLookups);
    }

    public function testTopLevelNameSkipsTheAncestorLookup(): void
    {
        $service = $this->makeService();

        $service->findConflictingZone('com', self::USER_ID);

        $this->assertSame([], $this->ancestorLookups);
        $this->assertSame(['com'], $this->descendantLookups);
    }

    public function testClosestParentDecides(): void
    {
        // Both ancestors exist; the more-specific one owned by another user wins.
        $service = $this->makeService(
            ancestorRows: ['com' => 99, 'a.com' => 14],
            ownedZoneIds: [99]
        );

        $this->assertSame('a.com', $service->findConflictingZone('b.a.com', self::USER_ID));
    }

    public function testOwnedClosestParentStopsTheAncestorWalk(): void
    {
        // Owning the closest parent is legitimate sub-delegation even when a
        // farther ancestor belongs to someone else.
        $service = $this->makeService(
            ancestorRows: ['com' => 99, 'a.com' => 14],
            ownedZoneIds: [14]
        );

        $this->assertNull($service->findConflictingZone('b.a.com', self::USER_ID));
    }

    public function testAncestorMatchIsCaseInsensitive(): void
    {
        // A case-insensitive collation can return a mixed-case row for the
        // lowercased lookup; it must still be detected.
        $service = $this->makeService(ancestorRows: ['A.CoM' => 14]);

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

    public function testFirstForeignDescendantInRepositoryOrderIsReported(): void
    {
        $service = $this->makeService(
            descendantRows: [['id' => 15, 'name' => 'b.a.com'], ['id' => 16, 'name' => 'c.a.com']],
            ownedZoneIds: [15]
        );

        $this->assertSame('c.a.com', $service->findConflictingZone('a.com', self::USER_ID));
    }

    public function testCoversReverseZones(): void
    {
        // A /24 nested under a /16 owned by another user is blocked too.
        $service = $this->makeService(ancestorRows: ['10.in-addr.arpa' => 20]);

        $this->assertSame('10.in-addr.arpa', $service->findConflictingZone('1.10.in-addr.arpa', self::USER_ID));
    }

    public function testUeberuserBypassesCheck(): void
    {
        $service = $this->makeService(
            ancestorRows: ['a.com' => 14],
            isAdmin: true
        );

        $this->assertNull($service->findConflictingZone('b.a.com', self::USER_ID));
        $this->assertSame([], $this->ancestorLookups);
    }

    public function testDisabledCheckAllowsEverything(): void
    {
        $service = $this->makeService(
            ancestorRows: ['a.com' => 14],
            checkEnabled: false
        );

        $this->assertNull($service->findConflictingZone('b.a.com', self::USER_ID));
        $this->assertSame([], $this->ancestorLookups);
    }
}
