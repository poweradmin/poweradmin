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

namespace Poweradmin\Domain\Repository;

use Poweradmin\Domain\Model\UserGroup;

interface UserGroupRepositoryInterface
{
    /**
     * Find all groups
     *
     * @return UserGroup[]
     */
    public function findAll(): array;

    /**
     * Find groups by user ID (groups the user belongs to)
     *
     * @param int $userId
     * @return UserGroup[]
     */
    public function findByUserId(int $userId): array;

    /**
     * Find a group by ID
     *
     * @param int $id
     * @return UserGroup|null
     */
    public function findById(int $id): ?UserGroup;

    /**
     * Find a group by name
     *
     * @param string $name
     * @return UserGroup|null
     */
    public function findByName(string $name): ?UserGroup;

    /**
     * Save (create or update) a group
     *
     * @param UserGroup $group
     * @return UserGroup
     */
    public function save(UserGroup $group): UserGroup;

    /**
     * Delete a group by ID
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Count members in a group
     *
     * @param int $groupId
     * @return int
     */
    public function countMembers(int $groupId): int;

    /**
     * Count zones owned by a group
     *
     * @param int $groupId
     * @return int
     */
    public function countZones(int $groupId): int;
}
