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

namespace Poweradmin\Domain\Repository;

/**
 * Account administration: listing, creating, updating and deleting users and moving their zones.
 */
interface UserAdminInterface
{
    /**
     * Get the full names of all owners of a zone, comma-separated
     *
     * @param int $domainId Domain/zone ID
     * @return string Comma-separated owner full names, empty string if none
     */
    public function getZoneOwnerFullNames(int $domainId): string;

    /**
     * Get a paginated list of users with zone counts
     *
     * @param int $offset Starting offset for pagination
     * @param int $limit Maximum number of users to return
     * @return array Array of user data with zone counts
     */
    public function getUsersList(int $offset, int $limit): array;

    /**
     * Get all users with the number of zones each one owns
     *
     * @return array Array of user rows [id, username, fullname, email, description, active, numdomains]
     */
    public function getUsersWithZoneCounts(): array;

    /**
     * Get detailed user list with template, group, and MFA info
     *
     * @param bool $ldapUse Whether the LDAP column should be included
     * @param int|null $restrictToUserId Return only this user (for users without view-others permission)
     * @param int|null $specific User ID to fetch (overrides the restriction)
     * @param int|null $limit Number of records to return (optional)
     * @param int|null $offset Starting offset (optional)
     * @param string|null $search Filter on username, full name, email or description
     * @return array Array of user details
     */
    public function getUserDetailList(bool $ldapUse, ?int $restrictToUserId, ?int $specific = null, ?int $limit = null, ?int $offset = null, ?string $search = null): array;

    /**
     * Get total count of users in the system
     *
     * @param int|null $restrictToUserId Count only this user (for users without view-others permission)
     * @param string|null $search Filter on username, full name, email or description
     * @return int Total number of users
     */
    public function getTotalUserCount(?int $restrictToUserId = null, ?string $search = null): int;

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
     * Create a new user
     *
     * @param array $userData User data containing username, password, email, etc.
     * @return int|null User ID if created successfully, null otherwise
     */
    public function createUser(array $userData): ?int;

    /**
     * Update a user's information
     *
     * @param int $userId User ID to update
     * @param array $userData Array of user data to update
     * @return bool True if updated successfully, false otherwise
     */
    public function updateUser(int $userId, array $userData): bool;

    /**
     * Assign permission template to a user
     *
     * @param int $userId User ID
     * @param int $permTemplId Permission template ID
     * @return bool True if assignment was successful
     */
    public function assignPermissionTemplate(int $userId, int $permTemplId): bool;

    /**
     * Check if a permission template exists
     *
     * @param int $permTemplId Permission template ID
     * @param string|null $templateType Optional template_type filter ('user' or 'group')
     * @return bool True if the permission template exists (and matches type when set)
     */
    public function permissionTemplateExists(int $permTemplId, ?string $templateType = null): bool;
}
