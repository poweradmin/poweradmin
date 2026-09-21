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

/**
 * Creating, updating and deleting groups, plus the counts that guard a delete.
 */
interface UserGroupWriterInterface
{
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
     * Get member counts for multiple groups in a single query
     *
     * @param int[] $groupIds
     * @return array<int, int> Map of group_id => member_count
     */
    public function getMemberCountsByGroupIds(array $groupIds): array;

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
