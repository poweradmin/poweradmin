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

use Poweradmin\Domain\Model\UserGroupMember;

interface UserGroupMemberRepositoryInterface
{
    /**
     * Find all members of a group
     *
     * @param int $groupId
     * @return UserGroupMember[]
     */
    public function findByGroupId(int $groupId): array;

    /**
     * Find all groups a user belongs to
     *
     * @param int $userId
     * @return UserGroupMember[]
     */
    public function findByUserId(int $userId): array;

    /**
     * Add a user to a group
     *
     * @param int $groupId
     * @param int $userId
     * @return UserGroupMember
     */
    public function add(int $groupId, int $userId): UserGroupMember;

    /**
     * Remove a user from a group
     *
     * @param int $groupId
     * @param int $userId
     * @return bool
     */
    public function remove(int $groupId, int $userId): bool;

    /**
     * Check if a user is a member of a group
     *
     * @param int $groupId
     * @param int $userId
     * @return bool
     */
    public function exists(int $groupId, int $userId): bool;
}
