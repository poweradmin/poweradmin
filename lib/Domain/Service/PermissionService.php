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

use Poweradmin\Domain\Enum\ZoneKind;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\UserRepositoryInterface;

/**
 * Answers permission questions for a user from their template and group grants, cached per request.
 *
 * This service provides methods to check and retrieve user permissions
 * using Domain-Driven Design principles.
 */
class PermissionService
{
    public const TEMPLATE_ASSIGN_DENIED = 'Setting perm_templ requires user_edit_templ_perm or user_is_ueberuser';
    public const TEMPLATE_SELF_ASSIGN_DENIED = 'Changing your own permission template requires user_edit_others';
    public const TEMPLATE_SUPERUSER_DENIED = 'Assigning a superuser permission template requires user_is_ueberuser';

    private UserRepositoryInterface $userRepository;

    /** @var array<int, array<string>> */
    private array $permissionsCache = [];

    /** @var array<int, bool> */
    private array $adminCache = [];

    /** @var array<string, bool> */
    private array $ownershipCache = [];

    public function __construct(UserRepositoryInterface $userRepository)
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
     * Check whether a user may create, edit or delete groups.
     *
     * Group management is superuser-only, unlike user permission templates which
     * `user_edit_templ_perm` delegates. Two reasons: that permission is defined as
     * covering the template assigned to *users*, and a group's template lands in the
     * same global permission union as a user's, so delegating it would hand out a
     * second, unguarded route to superuser.
     *
     * @param int $userId User ID to check
     * @return bool True if the user may manage groups
     */
    public function canManageGroups(int $userId): bool
    {
        return $this->isAdmin($userId);
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
        return $this->ownershipCache["$userId:$domainId"] ??= $this->userRepository->userOwnsZone($userId, $domainId);
    }

    /**
     * Whether the user may perform an "_own" action on a zone: the grant comes from
     * the user's template or any of their groups (union), and the zone is owned
     * directly or through any group.
     *
     * @param string $permissionName Permission name (e.g. 'zone_delete_own')
     */
    public function canPerformZoneAction(int $userId, int $domainId, string $permissionName): bool
    {
        if ($this->isAdmin($userId)) {
            return true;
        }

        return $this->hasPermission($userId, $permissionName) && $this->userOwnsZone($userId, $domainId);
    }

    /**
     * Get view permission level for a user
     *
     * @param int $userId User ID to check
     * @return string "all", "own", or "none" depending on the user's view permission
     */
    public function getViewPermissionLevel(int $userId): string
    {
        // Covers the user's own template and their groups' templates.
        $permissions = $this->getUserPermissions($userId);

        if (in_array(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS, $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array(Permission::PERM_ZONE_CONTENT_VIEW_OWN, $permissions)) {
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
        // Covers the user's own template and their groups' templates.
        $permissions = $this->getUserPermissions($userId);

        if (in_array(Permission::PERM_ZONE_CONTENT_EDIT_OTHERS, $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array(Permission::PERM_ZONE_CONTENT_EDIT_OWN, $permissions)) {
            return 'own';
        } elseif (in_array(Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT, $permissions)) {
            return 'own_as_client';
        } else {
            return 'none';
        }
    }

    /**
     * The user's edit level narrowed to one zone: "own" levels apply only when
     * the zone is owned directly or via any group.
     *
     * @return string "all", "own", "own_as_client", or "none"
     */
    public function getEditPermissionLevelForZone(int $userId, int $domainId): string
    {
        return $this->narrowLevelToZone($this->getEditPermissionLevel($userId), $userId, $domainId);
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

        if (in_array(Permission::PERM_ZONE_META_EDIT_OTHERS, $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array(Permission::PERM_ZONE_META_EDIT_OWN, $permissions)) {
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
            in_array(Permission::PERM_ZONE_METADATA_VIEW_OTHERS, $permissions)
            || in_array(Permission::PERM_ZONE_META_EDIT_OTHERS, $permissions)
            || $this->isAdmin($userId)
        ) {
            return 'all';
        } elseif (
            in_array(Permission::PERM_ZONE_METADATA_VIEW_OWN, $permissions)
            || in_array(Permission::PERM_ZONE_META_EDIT_OWN, $permissions)
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
            in_array(Permission::PERM_ZONE_OWNERSHIP_VIEW_OTHERS, $permissions)
            || in_array(Permission::PERM_ZONE_META_EDIT_OTHERS, $permissions)
            || $this->isAdmin($userId)
        ) {
            return 'all';
        } elseif (
            in_array(Permission::PERM_ZONE_OWNERSHIP_VIEW_OWN, $permissions)
            || in_array(Permission::PERM_ZONE_META_EDIT_OWN, $permissions)
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
        return $this->hasPermission($userId, Permission::PERM_USER_VIEW_OTHERS) || $this->isAdmin($userId);
    }

    /**
     * Whether the user may create (or convert a zone into) the given kind.
     * Kinds that replicate from a primary (SLAVE, CONSUMER) are governed by
     * zone_slave_add, the rest by zone_master_add; the add forms use the same split.
     */
    public function canCreateZone(int $userId, string $zoneType): bool
    {
        $kind = ZoneKind::tryFromName($zoneType);
        if ($kind === null) {
            return false;
        }
        $grant = $kind->replicatesFromPrimary() ? Permission::PERM_ZONE_SLAVE_ADD : Permission::PERM_ZONE_MASTER_ADD;

        return $this->hasPermission($userId, $grant) || $this->isAdmin($userId);
    }

    /**
     * Check if user can add zone templates
     *
     * @param int $userId User ID to check
     * @return bool True if user can add zone templates
     */
    public function canAddZoneTemplates(int $userId): bool
    {
        return $this->hasPermission($userId, Permission::PERM_ZONE_TEMPL_ADD) || $this->isAdmin($userId);
    }

    /**
     * Check if a user may access user management (any view/edit/add grant or admin).
     *
     * @param int $userId User ID to check
     * @return bool True if the user may manage other users
     */
    public function canManageUsers(int $userId): bool
    {
        return $this->hasPermission($userId, Permission::PERM_USER_VIEW_OTHERS)
            || $this->hasPermission($userId, Permission::PERM_USER_EDIT_OTHERS)
            || $this->hasPermission($userId, Permission::PERM_USER_ADD_NEW)
            || $this->isAdmin($userId);
    }

    /**
     * DNSSEC key management on a zone: zone_dnssec_manage_own plus ownership (admins always).
     */
    public function canManageDnssecForZone(int $userId, int $domainId): bool
    {
        return $this->canPerformZoneAction($userId, $domainId, Permission::PERM_ZONE_DNSSEC_MANAGE_OWN);
    }

    /**
     * Whether the user may open a zone: view_others (or admin), or view_own on a
     * zone owned directly or via any group.
     */
    public function canViewZone(int $userId, int $domainId): bool
    {
        return ZoneAccessPolicy::levelAppliesToZone(
            $this->getViewPermissionLevel($userId),
            $this->userOwnsZone($userId, $domainId)
        );
    }

    /**
     * Whether the user holds a content-edit grant that applies to the zone: edit_others
     * (or admin), or edit_own on an owned zone. own_as_client is NOT included, so this
     * is the gate for record types clients may not touch and never for zone metadata.
     */
    public function hasZoneContentEditPermission(int $userId, int $domainId): bool
    {
        $permissions = $this->getUserPermissions($userId);

        if (in_array(Permission::PERM_ZONE_CONTENT_EDIT_OTHERS, $permissions) || $this->isAdmin($userId)) {
            return true;
        }

        return in_array(Permission::PERM_ZONE_CONTENT_EDIT_OWN, $permissions) && $this->userOwnsZone($userId, $domainId);
    }

    /**
     * Whether the user may edit records in the zone. Secondary and Consumer zones
     * replicate from a primary and reject every content edit when the type is known.
     */
    public function canEditZoneContent(int $userId, int $domainId, ?string $zoneType = null): bool
    {
        if ($zoneType !== null && ZoneType::isReadOnly($zoneType)) {
            return false;
        }

        return $this->getEditPermissionLevelForZone($userId, $domainId) !== 'none';
    }

    /**
     * canEditZoneContent() plus the own_as_client record-type restriction: SOA/NS/LUA
     * need edit_own or better, except subzone NS records for zone_content_edit_ns_subzone
     * holders. Pass the record and zone names (FQDN) to enable that exemption.
     */
    public function canEditZoneRecord(
        int $userId,
        int $domainId,
        string $recordType,
        ?string $zoneType = null,
        ?string $recordName = null,
        ?string $zoneName = null
    ): bool {
        if (!$this->canEditZoneContent($userId, $domainId, $zoneType)) {
            return false;
        }

        if (!in_array(strtoupper($recordType), Permission::RESTRICTED_TYPES_FOR_CLIENT, true)) {
            return true;
        }

        if ($this->hasZoneContentEditPermission($userId, $domainId)) {
            return true;
        }

        return Permission::isSubzoneNsRecord($recordType, $recordName, $zoneName)
            && $this->hasPermission($userId, Permission::PERM_EDIT_NS_SUBZONE);
    }

    /**
     * Zone metadata (name, type, primaries): meta_edit_others (or admin), or meta_edit_own on an owned zone.
     */
    public function canEditZoneMeta(int $userId, int $domainId): bool
    {
        return ZoneAccessPolicy::levelAppliesToZone(
            $this->getZoneMetaEditPermissionLevel($userId),
            $this->userOwnsZone($userId, $domainId)
        );
    }

    public function canViewZoneMetadata(int $userId, int $domainId): bool
    {
        return ZoneAccessPolicy::levelAppliesToZone(
            $this->getZoneMetadataViewPermissionLevel($userId),
            $this->userOwnsZone($userId, $domainId)
        );
    }

    public function canViewZoneOwnership(int $userId, int $domainId): bool
    {
        return ZoneAccessPolicy::levelAppliesToZone(
            $this->getZoneOwnershipViewPermissionLevel($userId),
            $this->userOwnsZone($userId, $domainId)
        );
    }

    public function templateGrantsUberuser(int $permTemplId): bool
    {
        return $this->userRepository->templateGrantsUberuser($permTemplId);
    }

    /**
     * Why the actor may not put the target on this permission template, or null
     * when allowed. Echoing back an unchanged ordinary template is not a change.
     * The strings are API contract.
     */
    public function checkPermissionTemplateAssignment(int $actorId, ?int $targetUserId, int $permTemplId): ?string
    {
        if ($this->isAdmin($actorId)) {
            return null;
        }

        if ($targetUserId !== null) {
            $target = $this->userRepository->getUserById($targetUserId);
            $currentTemplId = isset($target['perm_templ']) ? (int)$target['perm_templ'] : null;
            if ($currentTemplId === $permTemplId && !$this->userRepository->templateGrantsUberuser($permTemplId)) {
                return null;
            }
        }

        if (!$this->hasPermission($actorId, Permission::PERM_USER_EDIT_TEMPL_PERM)) {
            return self::TEMPLATE_ASSIGN_DENIED;
        }

        if ($actorId === $targetUserId && !$this->hasPermission($actorId, Permission::PERM_USER_EDIT_OTHERS)) {
            return self::TEMPLATE_SELF_ASSIGN_DENIED;
        }

        if ($this->templateGrantsUberuser($permTemplId)) {
            return self::TEMPLATE_SUPERUSER_DENIED;
        }

        return null;
    }

    /**
     * Zone log access level: "all" (others or admin), "own", or "none".
     */
    public function getZoneLogPermissionLevel(int $userId): string
    {
        $permissions = $this->getUserPermissions($userId);

        if (in_array(Permission::PERM_ZONE_LOGS_VIEW_OTHERS, $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array(Permission::PERM_ZONE_LOGS_VIEW_OWN, $permissions)) {
            return 'own';
        }

        return 'none';
    }

    /**
     * Change request level: "all" (others or admin), "own", or "none". Decides
     * whether the user may file a change request instead of editing directly.
     */
    public function getChangeRequestPermissionLevel(int $userId): string
    {
        $permissions = $this->getUserPermissions($userId);

        if (in_array(Permission::PERM_ZONE_CHANGE_REQUEST_OTHERS, $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array(Permission::PERM_ZONE_CHANGE_REQUEST_OWN, $permissions)) {
            return 'own';
        }

        return 'none';
    }

    /**
     * Change approve level: "all" (others or admin), "own", or "none". Reviewing
     * additionally requires the edit permission, see ChangeApprovalPolicy::canReview().
     */
    public function getChangeApprovePermissionLevel(int $userId): string
    {
        $permissions = $this->getUserPermissions($userId);

        if (in_array(Permission::PERM_ZONE_CHANGE_APPROVE_OTHERS, $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array(Permission::PERM_ZONE_CHANGE_APPROVE_OWN, $permissions)) {
            return 'own';
        }

        return 'none';
    }

    /**
     * The user's change request level narrowed to one zone: "own" applies only
     * when the zone is owned directly or via any group.
     *
     * @return string "all", "own", or "none"
     */
    public function getChangeRequestPermissionLevelForZone(int $userId, int $domainId): string
    {
        return $this->narrowLevelToZone($this->getChangeRequestPermissionLevel($userId), $userId, $domainId);
    }

    /**
     * The user's change approve level narrowed to one zone: "own" applies only
     * when the zone is owned directly or via any group.
     *
     * @return string "all", "own", or "none"
     */
    public function getChangeApprovePermissionLevelForZone(int $userId, int $domainId): string
    {
        return $this->narrowLevelToZone($this->getChangeApprovePermissionLevel($userId), $userId, $domainId);
    }

    private function narrowLevelToZone(string $level, int $userId, int $domainId): string
    {
        if ($level === 'all' || $level === 'none') {
            return $level;
        }

        return $this->userOwnsZone($userId, $domainId) ? $level : 'none';
    }

    /**
     * Several permissions at once, as name => granted, for templates that branch on them.
     *
     * @param string[] $permissionNames
     * @return array<string, bool>
     */
    public function getPermissionFlags(int $userId, array $permissionNames): array
    {
        $flags = [];
        foreach ($permissionNames as $name) {
            $flags[$name] = $this->hasPermission($userId, $name);
        }

        return $flags;
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

        if (in_array(Permission::PERM_ZONE_DELETE_OTHERS, $permissions) || $this->isAdmin($userId)) {
            return 'all';
        } elseif (in_array(Permission::PERM_ZONE_DELETE_OWN, $permissions)) {
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

        return ZoneAccessPolicy::levelAppliesToZone($deleteLevel, $isOwner);
    }

    /**
     * Whether the user may delete this zone; ownership is looked up only when
     * their delete level depends on it.
     */
    public function canDeleteZoneById(int $userId, int $domainId): bool
    {
        if ($this->isAdmin($userId)) {
            return true;
        }

        return match ($this->getDeletePermissionLevel($userId)) {
            'all' => true,
            'own' => $this->userOwnsZone($userId, $domainId),
            default => false,
        };
    }
}
