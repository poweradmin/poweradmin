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
 * Read access to what a user may do: permissions, uberuser status, template grants and zone ownership.
 */
interface UserPermissionReadInterface
{
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
     * Check whether a permission template grants the uberuser permission
     *
     * @param int $permTemplId Permission template ID
     * @return bool True if the template grants user_is_ueberuser
     */
    public function templateGrantsUberuser(int $permTemplId): bool;

    /**
     * Id of the permission template with exactly this name, accent-exact so an
     * IdP-asserted claim cannot map to a look-alike template.
     */
    public function findPermissionTemplateIdByName(string $name): ?int;

    /**
     * Resolve every permission id carrying a given name
     *
     * perm_items.name has no unique constraint, so a name can map to several rows and
     * all of them grant the permission.
     *
     * @param string $name Permission name as stored in perm_items
     * @return array<int, int> Matching permission ids, empty when the permission is absent
     */
    public function getPermissionIdsByName(string $name): array;

    /**
     * Check if a specific user is an uberuser
     *
     * @param int $userId User ID to check
     * @return bool True if user is an uberuser
     */
    public function isUberuser(int $userId): bool;

    /**
     * Count total number of uberusers (super admins) in the system
     *
     * @return int Number of uberusers
     */
    public function countUberusers(): int;

    /**
     * Whether removing or demoting this user would leave no active uberuser.
     */
    public function isLastUberuser(int $userId): bool;

    /**
     * Check if a user owns a zone directly or via group membership
     *
     * @param int $userId User ID to check
     * @param int $domainId Domain/zone ID
     * @return bool True if the user owns the zone
     */
    public function userOwnsZone(int $userId, int $domainId): bool;

    /**
     * Ids of the groups the user is a member of
     *
     * @return array<int, int>
     */
    public function getUserGroupIds(int $userId): array;

    /**
     * Canonical ids of the zones the user owns directly or through any group
     *
     * @return array<int, int>
     */
    public function getUserOwnedZoneIds(int $userId): array;
}
