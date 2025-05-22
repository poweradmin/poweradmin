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

    /**
     * Get a paginated list of users with zone counts
     *
     * @param int $offset Starting offset for pagination
     * @param int $limit Maximum number of users to return
     * @return array Array of user data with zone counts
     */
    public function getUsersList(int $offset, int $limit): array;

    /**
     * Get total count of users in the system
     *
     * @return int Total number of users
     */
    public function getTotalUserCount(): int;

    /**
     * Delete a user by ID
     *
     * @param int $userId User ID to delete
     * @return bool True if the user was deleted successfully
     */
    public function deleteUser(int $userId): bool;

    /**
     * Get zones owned by a user
     *
     * @param int $userId User ID
     * @return array Array of zone data owned by the user
     */
    public function getUserZones(int $userId): array;

    /**
     * Transfer zone ownership from one user to another
     *
     * @param int $fromUserId Source user ID
     * @param int $toUserId Target user ID
     * @return bool True if zones were transferred successfully
     */
    public function transferUserZones(int $fromUserId, int $toUserId): bool;

    /**
     * Unassign all zones owned by a user (set owner to NULL)
     *
     * @param int $userId User ID
     * @return bool True if zones were unassigned successfully
     */
    public function unassignUserZones(int $userId): bool;

    /**
     * Count total number of uberusers (super admins) in the system
     *
     * @return int Number of uberusers
     */
    public function countUberusers(): int;

    /**
     * Check if a specific user is an uberuser
     *
     * @param int $userId User ID to check
     * @return bool True if user is an uberuser
     */
    public function isUberuser(int $userId): bool;

    /**
     * Create a new user
     *
     * @param array $userData User data containing username, password, email, etc.
     * @return int|null User ID if created successfully, null otherwise
     */
    public function createUser(array $userData): ?int;

    /**
     * Get a user by username
     *
     * @param string $username Username to search for
     * @return array|null User data if found, null otherwise
     */
    public function getUserByUsername(string $username): ?array;

    /**
     * Get a user by email
     *
     * @param string $email Email to search for
     * @return array|null User data if found, null otherwise
     */
    public function getUserByEmail(string $email): ?array;

    /**
     * Update a user's information
     *
     * @param int $userId User ID to update
     * @param array $userData Array of user data to update
     * @return bool True if updated successfully, false otherwise
     */
    public function updateUser(int $userId, array $userData): bool;
}
