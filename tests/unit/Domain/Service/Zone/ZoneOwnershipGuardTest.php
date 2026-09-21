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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipRefusal;

/**
 * The last-owner rule, decided once for every ownership writer.
 */
class ZoneOwnershipGuardTest extends TestCase
{
    private const ZONE_ID = 7;

    /**
     * @return array<string, array{0: string, 1: list<int>, 2: list<int>, 3: int, 4: string|null}>
     */
    public static function userOwnerCases(): array
    {
        return [
            'both, sole owner, no groups' => ['both', [5], [], 5, ZoneOwnershipRefusal::LAST_OWNER],
            'both, sole owner, a group remains' => ['both', [5], [3], 5, null],
            'both, second owner' => ['both', [5, 6], [], 5, null],
            'users_only, sole owner, legacy group' => ['users_only', [5], [3], 5, ZoneOwnershipRefusal::LAST_USER_OWNER_USERS_ONLY],
            'users_only, second owner' => ['users_only', [5, 6], [], 5, null],
            'groups_only, sole owner, no groups' => ['groups_only', [5], [], 5, ZoneOwnershipRefusal::LAST_OWNER],
            'groups_only, second owner, no groups' => ['groups_only', [5, 6], [], 5, ZoneOwnershipRefusal::GROUPS_ONLY_NO_GROUPS],
            'groups_only, sole owner, a group remains' => ['groups_only', [5], [3], 5, null],
            'not an owner' => ['both', [5], [], 9, null],
        ];
    }

    /**
     * @param list<int> $owners
     * @param list<int> $groups
     */
    #[DataProvider('userOwnerCases')]
    public function testUserOwnerRemoval(string $mode, array $owners, array $groups, int $userId, ?string $code): void
    {
        $refusal = $this->guard($mode, $owners, $groups)->refuseUserOwnerRemoval(self::ZONE_ID, $userId);

        $this->assertSame($code, $refusal?->code);
        if ($code !== null) {
            $this->assertSame($mode, $refusal->mode);
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<int>, 2: list<int>, 3: int, 4: string|null}>
     */
    public static function groupCases(): array
    {
        return [
            'both, sole group, no owners' => ['both', [], [3], 3, ZoneOwnershipRefusal::LAST_OWNER],
            'both, sole group, an owner remains' => ['both', [5], [3], 3, null],
            'both, second group' => ['both', [], [3, 4], 3, null],
            'groups_only, sole group, legacy owner' => ['groups_only', [5], [3], 3, ZoneOwnershipRefusal::LAST_GROUP_GROUPS_ONLY],
            'groups_only, second group' => ['groups_only', [], [3, 4], 3, null],
            'users_only, sole group, no owners' => ['users_only', [], [3], 3, ZoneOwnershipRefusal::LAST_OWNER],
            'users_only, second group, no owners' => ['users_only', [], [3, 4], 3, ZoneOwnershipRefusal::USERS_ONLY_NO_USER_OWNERS],
            'users_only, sole group, an owner remains' => ['users_only', [5], [3], 3, null],
            'not an owner' => ['both', [], [3], 9, null],
        ];
    }

    /**
     * @param list<int> $owners
     * @param list<int> $groups
     */
    #[DataProvider('groupCases')]
    public function testGroupRemoval(string $mode, array $owners, array $groups, int $groupId, ?string $code): void
    {
        $refusal = $this->guard($mode, $owners, $groups)->refuseGroupRemoval(self::ZONE_ID, $groupId);

        $this->assertSame($code, $refusal?->code);
        if ($code !== null) {
            $this->assertSame($mode, $refusal->mode);
        }
    }

    /**
     * @param list<int> $owners
     * @param list<int> $groups
     */
    private function guard(string $mode, array $owners, array $groups): ZoneOwnershipGuard
    {
        $zoneRepository = $this->createMock(ZoneOwnershipRepositoryInterface::class);
        $zoneRepository->method('getZoneOwners')->with(self::ZONE_ID)->willReturn(
            array_map(static fn(int $id): array => ['id' => $id, 'fullname' => 'User ' . $id], $owners)
        );
        $zoneGroupRepository = $this->createMock(ZoneGroupRepositoryInterface::class);
        $zoneGroupRepository->method('findByDomainId')->with(self::ZONE_ID)->willReturn(
            array_map(static fn(int $id): ZoneGroup => ZoneGroup::create(self::ZONE_ID, $id), $groups)
        );
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->with('dns', 'zone_ownership_mode')->willReturn($mode);

        return new ZoneOwnershipGuard($zoneRepository, $zoneGroupRepository, new ZoneOwnershipModeService($config));
    }
}
