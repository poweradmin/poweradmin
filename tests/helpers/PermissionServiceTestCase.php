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

namespace TestHelpers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;

/**
 * Builds a real PermissionService over a scripted UserRepositoryInterface, so tests state
 * facts (who holds what, who owns what) instead of ordered query results.
 */
abstract class PermissionServiceTestCase extends TestCase
{
    /**
     * @param array<int, string[]> $permissionsByUser user id => granted permission names
     * @param int[] $adminUserIds users holding user_is_ueberuser
     * @param array<int, int[]> $ownedZonesByUser user id => zone ids owned directly or via a group
     * @param array<int, int> $templateByUser user id => perm_templ id
     * @param int[] $superuserTemplateIds templates that grant user_is_ueberuser
     * @param array<int, int[]> $groupIdsByUser user id => ids of the groups the user belongs to
     */
    protected function buildPermissionService(
        array $permissionsByUser = [],
        array $adminUserIds = [],
        array $ownedZonesByUser = [],
        array $templateByUser = [],
        array $superuserTemplateIds = [],
        array $groupIdsByUser = []
    ): PermissionService {
        return new PermissionService($this->scriptedUserRepository(
            $permissionsByUser,
            $adminUserIds,
            $ownedZonesByUser,
            $templateByUser,
            $superuserTemplateIds,
            $groupIdsByUser
        ));
    }

    /**
     * @param array<int, string[]> $permissionsByUser
     * @param int[] $adminUserIds
     * @param array<int, int[]> $ownedZonesByUser
     * @param array<int, int> $templateByUser
     * @param int[] $superuserTemplateIds
     * @param array<int, int[]> $groupIdsByUser
     */
    protected function scriptedUserRepository(
        array $permissionsByUser = [],
        array $adminUserIds = [],
        array $ownedZonesByUser = [],
        array $templateByUser = [],
        array $superuserTemplateIds = [],
        array $groupIdsByUser = []
    ): UserRepositoryInterface&MockObject {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('getUserPermissions')
            ->willReturnCallback(fn(int $userId): array => $permissionsByUser[$userId] ?? []);
        $repository->method('hasAdminPermission')
            ->willReturnCallback(fn(int $userId): bool => in_array($userId, $adminUserIds, true));
        $repository->method('userOwnsZone')
            ->willReturnCallback(fn(int $userId, int $zoneId): bool => in_array($zoneId, $ownedZonesByUser[$userId] ?? [], true));
        $repository->method('getUserOwnedZoneIds')
            ->willReturnCallback(fn(int $userId): array => $ownedZonesByUser[$userId] ?? []);
        $repository->method('getUserGroupIds')
            ->willReturnCallback(fn(int $userId): array => $groupIdsByUser[$userId] ?? []);
        $repository->method('getUserById')
            ->willReturnCallback(fn(int $userId): ?array => isset($templateByUser[$userId])
                ? ['id' => $userId, 'perm_templ' => $templateByUser[$userId]]
                : null);
        $repository->method('templateGrantsUberuser')
            ->willReturnCallback(fn(int $templId): bool => in_array($templId, $superuserTemplateIds, true));

        return $repository;
    }
}
