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

use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Model\UserId;

interface UserRepository
{
    /**
     * Check if a user can view other users' content
     *
     * @param UserId $user User ID to check
     * @return bool True if the user can view others' content
     */
    public function canViewOthersContent(UserId $user): bool;

    /**
     * Find a user by username
     *
     * @param string $username Username to search for
     * @return User|null User object if found, null otherwise
     */
    public function findByUsername(string $username): ?User;

    /**
     * Update a user's password
     *
     * @param int $userId User ID to update
     * @param string $hashedPassword Hashed password to set
     * @return bool True if the password was updated successfully
     */
    public function updatePassword(int $userId, string $hashedPassword): bool;

    /**
     * Get a user by ID
     *
     * @param int $userId User ID to retrieve
     * @return array|null User data if found, null otherwise
     */
    public function getUserById(int $userId): ?array;

    /**
     * Get all permissions for a specific user
     *
     * @param int $userId User ID to get permissions for
     * @return array Array of permission names
     */
    public function getUserPermissions(int $userId): array;

    /**
     * Check if a user has admin permissions
     *
     * @param int $userId User ID to check
     * @return bool True if the user is an admin
     */
    public function hasAdminPermission(int $userId): bool;
}
