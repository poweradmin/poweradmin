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

namespace Poweradmin\Domain\Service;

use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Stateless permission service for API requests
 * Does not rely on session data - all checks use explicit user IDs
 *
 * @package Poweradmin\Domain\Service
 */
class ApiPermissionService
{
    private PDOCommon $db;

    public function __construct(PDOCommon $db)
    {
        $this->db = $db;
    }

    /**
     * Check if user has a specific permission (stateless)
     *
     * @param int $userId User ID to check
     * @param string $permissionName Permission name
     * @return bool True if user has permission
     */
    public function userHasPermission(int $userId, string $permissionName): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM perm_templ_items
            INNER JOIN perm_items ON perm_templ_items.perm_id = perm_items.id
            INNER JOIN users ON perm_templ_items.templ_id = users.perm_templ
            WHERE users.id = :user_id
            AND perm_items.name = :permission_name
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':permission_name' => $permissionName
        ]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Check if user is the owner of a specific zone (stateless)
     *
     * @param int $userId User ID to check
     * @param int $zoneId Zone ID (domain_id in PowerDNS)
     * @return bool True if user owns the zone
     */
    public function userOwnsZone(int $userId, int $zoneId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM zones
            WHERE zones.owner = :user_id
            AND zones.domain_id = :zone_id
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':zone_id' => $zoneId
        ]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Check if user can view a specific zone (stateless)
     *
     * @param int $userId User ID to check
     * @param int $zoneId Zone ID (domain_id in PowerDNS)
     * @return bool True if user can view the zone
     */
    public function canViewZone(int $userId, int $zoneId): bool
    {
        // Uberuser can view all zones
        if ($this->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        // User with zone_content_view_others can view all zones
        if ($this->userHasPermission($userId, 'zone_content_view_others')) {
            return true;
        }

        // User with zone_content_view_own can view their own zones
        if ($this->userHasPermission($userId, 'zone_content_view_own')) {
            return $this->userOwnsZone($userId, $zoneId);
        }

        return false;
    }

    /**
     * Check if user can edit a specific zone (stateless)
     *
     * @param int $userId User ID to check
     * @param int $zoneId Zone ID (domain_id in PowerDNS)
     * @return bool True if user can edit the zone
     */
    public function canEditZone(int $userId, int $zoneId): bool
    {
        // Uberuser can edit all zones
        if ($this->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        // User with zone_content_edit_others can edit all zones
        if ($this->userHasPermission($userId, 'zone_content_edit_others')) {
            return true;
        }

        // User with zone_content_edit_own can edit their own zones
        if ($this->userHasPermission($userId, 'zone_content_edit_own')) {
            return $this->userOwnsZone($userId, $zoneId);
        }

        return false;
    }

    /**
     * Check if user can delete a specific zone (stateless)
     *
     * @param int $userId User ID to check
     * @param int $zoneId Zone ID (domain_id in PowerDNS)
     * @return bool True if user can delete the zone
     */
    public function canDeleteZone(int $userId, int $zoneId): bool
    {
        // Uberuser can delete all zones
        if ($this->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        // User with zone_content_edit_others can delete all zones
        if ($this->userHasPermission($userId, 'zone_content_edit_others')) {
            return true;
        }

        // User with zone_content_edit_own can delete their own zones
        if ($this->userHasPermission($userId, 'zone_content_edit_own')) {
            return $this->userOwnsZone($userId, $zoneId);
        }

        return false;
    }

    /**
     * Check if user can create zones (stateless)
     *
     * @param int $userId User ID to check
     * @param string $zoneType Zone type (MASTER, SLAVE, NATIVE)
     * @return bool True if user can create zones of this type
     */
    public function canCreateZone(int $userId, string $zoneType = 'MASTER'): bool
    {
        // Uberuser can create all zone types
        if ($this->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        // Check specific permissions based on zone type
        $zoneType = strtoupper($zoneType);

        if ($zoneType === 'MASTER' || $zoneType === 'NATIVE') {
            return $this->userHasPermission($userId, 'zone_master_add');
        }

        if ($zoneType === 'SLAVE') {
            return $this->userHasPermission($userId, 'zone_slave_add');
        }

        return false;
    }

    /**
     * Check if user can view other users (stateless)
     *
     * @param int $userId User ID to check
     * @param int $targetUserId Target user ID being viewed
     * @return bool True if user can view the target user
     */
    public function canViewUser(int $userId, int $targetUserId): bool
    {
        // Uberuser can view all users
        if ($this->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        // User can view their own details
        if ($userId === $targetUserId) {
            return true;
        }

        // User with user_view_others can view all users
        if ($this->userHasPermission($userId, 'user_view_others')) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can edit another user (stateless)
     *
     * @param int $userId User ID to check
     * @param int $targetUserId Target user ID being edited
     * @return bool True if user can edit the target user
     */
    public function canEditUser(int $userId, int $targetUserId): bool
    {
        // Uberuser can edit all users
        if ($this->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        // User can edit their own details with user_edit_own
        if ($userId === $targetUserId && $this->userHasPermission($userId, 'user_edit_own')) {
            return true;
        }

        // User with user_edit_others can edit all users
        if ($this->userHasPermission($userId, 'user_edit_others')) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can create new users (stateless)
     *
     * @param int $userId User ID to check
     * @return bool True if user can create users
     */
    public function canCreateUser(int $userId): bool
    {
        // Uberuser can create users
        if ($this->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        // User with user_add_new can create users
        return $this->userHasPermission($userId, 'user_add_new');
    }

    /**
     * Check if user can delete another user (stateless)
     *
     * @param int $userId User ID to check
     * @param int $targetUserId Target user ID being deleted
     * @return bool True if user can delete the target user
     */
    public function canDeleteUser(int $userId, int $targetUserId): bool
    {
        // Uberuser can delete users (except themselves - business logic check elsewhere)
        if ($this->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        // User with user_edit_others can delete users (except themselves)
        if ($userId !== $targetUserId && $this->userHasPermission($userId, 'user_edit_others')) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can edit permission templates (stateless)
     *
     * @param int $userId User ID to check
     * @return bool True if user can edit permission templates
     */
    public function canEditPermissionTemplates(int $userId): bool
    {
        // Uberuser can edit permission templates
        if ($this->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        // User with user_edit_templ_perm can edit permission templates
        return $this->userHasPermission($userId, 'user_edit_templ_perm');
    }

    /**
     * Check if user can list all users (stateless)
     *
     * @param int $userId User ID to check
     * @return bool True if user can list users
     */
    public function canListUsers(int $userId): bool
    {
        // Uberuser can list all users
        if ($this->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        // User with user_view_others can list users
        return $this->userHasPermission($userId, 'user_view_others');
    }
}
