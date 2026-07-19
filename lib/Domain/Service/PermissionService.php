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

use Poweradmin\Application\Service\HybridPermissionService;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;

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
    /** @var array<int, array<string>> */
    private array $permissionsCache = [];

    /** @var array<int, bool> */
    private array $adminCache = [];

    private ?HybridPermissionService $hybridPermissionService = null;

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
        if ($this->isAdmin($userId)) {
            return true;
        }

        return in_array($permissionName, $this->getUserPermissions($userId));
    }

    /**
     * Get all permissions for a specific user (direct template and group-based)
     *
     * Permissions do not change within a request, so they are cached per user
     * to keep repeated checks at a single query.
     *
     * @param int $userId User ID to get permissions for
     * @return array Array of permission names
     */
    public function getUserPermissions(int $userId): array
    {
        return $this->permissionsCache[$userId] ??= $this->userRepository->getUserPermissions($userId);
    }

    /**
     * Check if a user is an admin (has the "überuser" permission)
     *
     * @param int $userId User ID to check
     * @return bool True if the user is an admin, false otherwise
     */
    public function isAdmin(int $userId): bool
    {
        return $this->adminCache[$userId] ??= $this->userRepository->hasAdminPermission($userId);
    }

    /**
     * Check if a user owns a zone directly or via group membership
     *
     * @param int $userId User ID to check
     * @param int $domainId Domain/zone ID
     * @return bool True if the user owns the zone
     */
    public function userOwnsZone(int $userId, int $domainId): bool
    {
        return $this->userRepository->userOwnsZone($userId, $domainId);
    }

    /**
     * Check if a user may perform an action on a zone, combining ownership
     * (direct or via group) with template and group permissions
     *
     * @param \PDO $db Database connection
     * @param int $userId User ID to check
     * @param int $domainId Zone/Domain ID
     * @param string $permissionName Permission name (e.g. 'zone_delete_own')
     * @return bool True if the user may perform the action on this zone
     */
    public function canPerformZoneAction(\PDO $db, int $userId, int $domainId, string $permissionName): bool
    {
        if ($this->isAdmin($userId)) {
            return true;
        }

        return $this->getHybridPermissionService($db)->canUserPerformAction($userId, $domainId, $permissionName);
    }

    /**
     * Get the permissions a user has for a zone from ownership and group membership.
     * Callers short-circuit admins first, so no ueberuser handling here.
     */
    private function getZonePermissions(\PDO $db, int $userId, int $domainId): array
    {
        $zonePermissions = $this->getHybridPermissionService($db)->getUserPermissionsForZone($userId, $domainId);
        return $zonePermissions['permissions'];
    }

    private function getHybridPermissionService(\PDO $db): HybridPermissionService
    {
        return $this->hybridPermissionService ??= new HybridPermissionService(
            $db,
            new DbUserGroupRepository($db),
            new DbUserGroupMemberRepository($db)
        );
    }

    /**
     * Get view permission level for a user
     *
     * @param int $userId User ID to check
     * @return string "all", "own", or "none" depending on the user's view permission
     */
    public function getViewPermissionLevel(int $userId): string
    {
        // Note: This checks DIRECT user permissions only
        // For zone-specific permissions (including groups), use getViewPermissionLevelForZone()
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
     * Get view permission level for a user on a specific zone (includes group permissions)
     *
     * @param \PDO $db Database connection
     * @param int $userId User ID to check
     * @param int $domainId Zone/Domain ID
     * @return string "all", "own", or "none" depending on the user's view permission for this zone
     */
    public function getViewPermissionLevelForZone(\PDO $db, int $userId, int $domainId): string
    {
        // Check direct permissions first
        $permissions = $this->getUserPermissions($userId);

        if (in_array('zone_content_view_others', $permissions) || $this->isAdmin($userId)) {
            return 'all';
        }

        // Check zone-specific permissions (direct ownership + group membership)
        $zonePermissions = $this->getZonePermissions($db, $userId, $domainId);

        if (in_array('zone_content_view_own', $zonePermissions)) {
            return 'own';
        }

        return 'none';
    }

    /**
     * Get edit permission level for a user
     *
     * @param int $userId User ID to check
     * @return string "all", "own", "own_as_client", or "none" depending on the user's edit permission
     */
    public function getEditPermissionLevel(int $userId): string
    {
        // Note: This checks DIRECT user permissions only
        // For zone-specific permissions (including groups), use getEditPermissionLevelForZone()
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
     * Get edit permission level for a user on a specific zone (includes group permissions)
     *
     * @param \PDO $db Database connection
     * @param int $userId User ID to check
     * @param int $domainId Zone/Domain ID
     * @return string "all", "own", "own_as_client", or "none" depending on the user's edit permission for this zone
     */
    public function getEditPermissionLevelForZone(\PDO $db, int $userId, int $domainId): string
    {
        // Check direct permissions first
        $permissions = $this->getUserPermissions($userId);

        if (in_array('zone_content_edit_others', $permissions) || $this->isAdmin($userId)) {
            return 'all';
        }

        // Check zone-specific permissions (direct ownership + group membership)
        $zonePermissions = $this->getZonePermissions($db, $userId, $domainId);

        if (in_array('zone_content_edit_own', $zonePermissions)) {
            return 'own';
        } elseif (in_array('zone_content_edit_own_as_client', $zonePermissions)) {
            return 'own_as_client';
        }

        return 'none';
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
     * Get zone metadata view permission level for a user
     *
     * Holders of zone_meta_edit_* may always see what they are allowed to edit.
     *
     * @param int $userId User ID to check
     * @return string "all", "own", or "none" depending on the user's metadata view permission
     */
    public function getZoneMetadataViewPermissionLevel(int $userId): string
    {
        $permissions = $this->getUserPermissions($userId);

        if (
            in_array('zone_metadata_view_others', $permissions)
            || in_array('zone_meta_edit_others', $permissions)
            || $this->isAdmin($userId)
        ) {
            return 'all';
        } elseif (
            in_array('zone_metadata_view_own', $permissions)
            || in_array('zone_meta_edit_own', $permissions)
        ) {
            return 'own';
        } else {
            return 'none';
        }
    }

    /**
     * Get zone ownership view permission level for a user
     *
     * Holders of zone_meta_edit_* may always see what they are allowed to edit.
     *
     * @param int $userId User ID to check
     * @return string "all", "own", or "none" depending on the user's ownership view permission
     */
    public function getZoneOwnershipViewPermissionLevel(int $userId): string
    {
        $permissions = $this->getUserPermissions($userId);

        if (
            in_array('zone_ownership_view_others', $permissions)
            || in_array('zone_meta_edit_others', $permissions)
            || $this->isAdmin($userId)
        ) {
            return 'all';
        } elseif (
            in_array('zone_ownership_view_own', $permissions)
            || in_array('zone_meta_edit_own', $permissions)
        ) {
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

    /**
     * Get DNSSEC management permission level for a user.
     *
     * Note: checks DIRECT user permissions only. For zone-aware checks that pick up
     * group-assigned permissions, use getDnssecPermissionLevelForZone().
     *
     * @param int $userId User ID to check
     * @return string "all", "own", or "none" depending on the user's DNSSEC management permission
     */
    public function getDnssecPermissionLevel(int $userId): string
    {
        if ($this->isAdmin($userId)) {
            return 'all';
        }

        $permissions = $this->getUserPermissions($userId);
        return in_array('zone_dnssec_manage_own', $permissions) ? 'own' : 'none';
    }

    /**
     * Get DNSSEC management permission level for a user on a specific zone (includes group permissions).
     *
     * @param \PDO $db Database connection
     * @param int $userId User ID to check
     * @param int $domainId Zone/Domain ID
     * @return string "all", "own", or "none" depending on the user's DNSSEC management permission for this zone
     */
    public function getDnssecPermissionLevelForZone(\PDO $db, int $userId, int $domainId): string
    {
        if ($this->isAdmin($userId)) {
            return 'all';
        }

        $zonePermissions = $this->getZonePermissions($db, $userId, $domainId);
        return in_array('zone_dnssec_manage_own', $zonePermissions) ? 'own' : 'none';
    }

    /**
     * Get delete permission level for a user
     *
     * @param int $userId User ID to check
     * @return string "all", "own", or "none" depending on the user's delete permission
     */
    public function getDeletePermissionLevel(int $userId): string
    {
        $permissions = $this->getUserPermissions($userId);

        if (in_array('zone_delete_others', $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array('zone_delete_own', $permissions)) {
            return 'own';
        } else {
            return 'none';
        }
    }

    /**
     * Check if user can delete a zone
     *
     * @param int $userId User ID to check
     * @param bool $isOwner Whether the user owns the zone
     * @return bool True if user can delete the zone
     */
    public function canDeleteZone(int $userId, bool $isOwner): bool
    {
        // Admins can always delete
        if ($this->isAdmin($userId)) {
            return true;
        }

        $deleteLevel = $this->getDeletePermissionLevel($userId);

        return $deleteLevel === 'all' || ($deleteLevel === 'own' && $isOwner);
    }
}
