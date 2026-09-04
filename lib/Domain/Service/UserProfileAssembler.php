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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

/**
 * Shapes raw user rows into the read format the API exposes
 *
 * Adds the derived fields a stored row does not carry: admin status, effective
 * permissions, and the group memberships that grant them.
 */
class UserProfileAssembler
{
    private PermissionService $permissionService;
    private UserGroupRepositoryInterface $groupRepository;

    public function __construct(
        PermissionService $permissionService,
        UserGroupRepositoryInterface $groupRepository
    ) {
        $this->permissionService = $permissionService;
        $this->groupRepository = $groupRepository;
    }

    /**
     * Full detail view of one user, including effective permissions
     *
     * @param array $user Raw repository row
     */
    public function assembleDetail(array $user): array
    {
        $userId = (int)$user['id'];

        return $this->baseFields($user) + [
            'is_admin' => $this->permissionService->isAdmin($userId),
            'permissions' => $this->permissionService->getUserPermissions($userId),
            'groups' => $this->formatGroups($this->groupRepository->findByUserId($userId)),
            'created_at' => $user['created_at'] ?? null,
            'updated_at' => $user['updated_at'] ?? null,
        ];
    }

    /**
     * List view of one user found by username or email
     *
     * @param array $user Raw repository row
     */
    public function assembleLookup(array $user): array
    {
        $groups = $this->groupRepository->findByUserId((int)$user['id']);

        // Zone count would need an additional query, so lookups report none
        return $this->assembleSummary($user, $groups, 0);
    }

    /**
     * List view of a page of users, resolving group memberships in one batched query
     *
     * @param array $users Raw repository rows
     * @return list<array>
     */
    public function assembleList(array $users): array
    {
        $groupsByUser = $this->groupRepository->findByUserIds(
            array_map(fn(array $user) => (int)$user['id'], $users)
        );

        return array_values(array_map(function (array $user) use ($groupsByUser) {
            $groups = $groupsByUser[(int)$user['id']] ?? [];

            return $this->assembleSummary($user, $groups, (int)($user['zone_count'] ?? 0));
        }, $users));
    }

    /**
     * Shared shape of the list and lookup views
     *
     * @param array $user Raw repository row
     * @param UserGroup[] $groups
     */
    private function assembleSummary(array $user, array $groups, int $zoneCount): array
    {
        return $this->baseFields($user) + [
            'zone_count' => $zoneCount,
            'is_admin' => $this->permissionService->isAdmin((int)$user['id']),
            'groups' => $this->formatGroups($groups),
        ];
    }

    /**
     * Fields every read shape carries, taken straight from the stored row
     *
     * @param array $user Raw repository row
     */
    private function baseFields(array $user): array
    {
        return [
            'user_id' => (int)$user['id'],
            'username' => $user['username'],
            'fullname' => $user['fullname'] ?? '',
            'email' => $user['email'] ?? '',
            'description' => $user['description'] ?? '',
            'active' => (bool)$user['active'],
            'perm_templ' => isset($user['perm_templ']) ? (int)$user['perm_templ'] : null,
            'perm_templ_name' => $user['perm_templ_name'] ?? null,
        ];
    }

    /**
     * @param UserGroup[] $groups
     * @return list<array{id: int, name: string}>
     */
    private function formatGroups(array $groups): array
    {
        return array_values(array_map(fn(UserGroup $group) => [
            'id' => (int)$group->getId(),
            'name' => $group->getName(),
        ], $groups));
    }
}
