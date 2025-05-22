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

use Poweradmin\Domain\Repository\UserRepository;

/**
 * Service for managing user permissions
 *
 * This service provides methods to check and retrieve user permissions
 * using Domain-Driven Design principles.
 */
class PermissionService
{
    private UserRepository $userRepository;

    /**
     * Constructor
     *
     * @param UserRepository $userRepository User repository for database access
     */
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * Check if a user has a specific permission
     *
     * @param int $userId User ID to check
     * @param string $permissionName Name of the permission to check
     * @return bool True if the user has the permission, false otherwise
     */
    public function hasPermission(int $userId, string $permissionName): bool
    {
        // Check if the user is an admin (has the "überuser" permission)
        if ($this->isAdmin($userId)) {
            return true;
        }

        // Get user permissions and check if the specified permission exists
        $permissions = $this->getUserPermissions($userId);
        return in_array($permissionName, $permissions);
    }

    /**
     * Get all permissions for a specific user
     *
     * @param int $userId User ID to get permissions for
     * @return array Array of permission names
     */
    public function getUserPermissions(int $userId): array
    {
        return $this->userRepository->getUserPermissions($userId);
    }

    /**
     * Check if a user is an admin (has the "überuser" permission)
     *
     * @param int $userId User ID to check
     * @return bool True if the user is an admin, false otherwise
     */
    public function isAdmin(int $userId): bool
    {
        return $this->userRepository->hasAdminPermission($userId);
    }

    /**
     * Get view permission level for a user
     *
     * @param int $userId User ID to check
     * @return string "all", "own", or "none" depending on the user's view permission
     */
    public function getViewPermissionLevel(int $userId): string
    {
        $permissions = $this->getUserPermissions($userId);

        if (in_array('zone_content_view_others', $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array('zone_content_view_own', $permissions)) {
            return 'own';
        } else {
            return 'none';
        }
    }

    /**
     * Get edit permission level for a user
     *
     * @param int $userId User ID to check
     * @return string "all", "own", "own_as_client", or "none" depending on the user's edit permission
     */
    public function getEditPermissionLevel(int $userId): string
    {
        $permissions = $this->getUserPermissions($userId);

        if (in_array('zone_content_edit_others', $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array('zone_content_edit_own', $permissions)) {
            return 'own';
        } elseif (in_array('zone_content_edit_own_as_client', $permissions)) {
            return 'own_as_client';
        } else {
            return 'none';
        }
    }

    /**
     * Get zone meta edit permission level for a user
     *
     * @param int $userId User ID to check
     * @return string "all", "own", or "none" depending on the user's meta edit permission
     */
    public function getZoneMetaEditPermissionLevel(int $userId): string
    {
        $permissions = $this->getUserPermissions($userId);

        if (in_array('zone_meta_edit_others', $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array('zone_meta_edit_own', $permissions)) {
            return 'own';
        } else {
            return 'none';
        }
    }

    /**
     * Check if user can view other users' content
     *
     * @param int $userId User ID to check
     * @return bool True if user can view others' content
     */
    public function canViewOthersContent(int $userId): bool
    {
        return $this->hasPermission($userId, 'user_view_others') || $this->isAdmin($userId);
    }

    /**
     * Check if user can add zones
     *
     * @param int $userId User ID to check
     * @return bool True if user can add zones
     */
    public function canAddZones(int $userId): bool
    {
        return $this->hasPermission($userId, 'zone_master_add') || $this->isAdmin($userId);
    }

    /**
     * Check if user can add zone templates
     *
     * @param int $userId User ID to check
     * @return bool True if user can add zone templates
     */
    public function canAddZoneTemplates(int $userId): bool
    {
        return $this->hasPermission($userId, 'zone_templ_add') || $this->isAdmin($userId);
    }
}
