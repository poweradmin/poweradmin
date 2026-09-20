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

use InvalidArgumentException;
use PDO;
use Poweradmin\Domain\Enum\ZoneKind;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * Permission gate for the public API. A facade over PermissionService so the API
 * and the web UI share one oracle; only the group and visible-zone lookups query here.
 */
class ApiPermissionService
{
    public const TEMPLATE_ASSIGN_DENIED = PermissionService::TEMPLATE_ASSIGN_DENIED;
    public const TEMPLATE_SELF_ASSIGN_DENIED = PermissionService::TEMPLATE_SELF_ASSIGN_DENIED;
    public const TEMPLATE_SUPERUSER_DENIED = PermissionService::TEMPLATE_SUPERUSER_DENIED;

    private PDO $db;
    private PermissionService $permissions;
    private ?ConfigurationInterface $config;

    /**
     * @param ConfigurationInterface|null $config Required when no PermissionService is given;
     *        omitted with one, the change approval flags read as off
     */
    public function __construct(PDO $db, ?PermissionService $permissions = null, ?ConfigurationInterface $config = null)
    {
        $this->db = $db;
        if ($permissions === null && $config === null) {
            throw new InvalidArgumentException('ApiPermissionService needs a PermissionService or a configuration to build one');
        }
        $this->permissions = $permissions ?? new PermissionService(new DbUserRepository($db, $config));
        $this->config = $config;
    }

    /**
     * The underlying oracle, for callers that take a PermissionService.
     */
    public function permissions(): PermissionService
    {
        return $this->permissions;
    }

    /**
     * Grant from the user's own template or any group template; ueberusers hold every permission.
     */
    public function userHasPermission(int $userId, string $permissionName): bool
    {
        return $this->permissions->hasPermission($userId, $permissionName);
    }

    /**
     * Return the group IDs the user is a member of.
     *
     * @return array<int, int>
     */
    public function getUserGroupIds(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT group_id FROM user_group_members WHERE user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows ?: []);
    }

    /**
     * Given a list of group IDs, return the subset that actually exists in user_groups.
     *
     * @param array<int> $groupIds
     * @return array<int, int>
     */
    public function getExistingGroupIds(array $groupIds): array
    {
        if (empty($groupIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $this->db->prepare("SELECT id FROM user_groups WHERE id IN ($placeholders)");
        $stmt->execute(array_values($groupIds));
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows ?: []);
    }

    /**
     * Direct ownership or through any group the user belongs to.
     */
    public function userOwnsZone(int $userId, int $zoneId): bool
    {
        return $this->permissions->userOwnsZone($userId, $zoneId);
    }

    public function canViewZone(int $userId, int $zoneId): bool
    {
        return $this->permissions->canViewZone($userId, $zoneId);
    }

    /**
     * Content-edit grant that applies to the zone (never own_as_client, never metadata).
     */
    public function hasZoneContentEditPermission(int $userId, int $zoneId): bool
    {
        return $this->permissions->hasZoneContentEditPermission($userId, $zoneId);
    }

    public function canEditZoneContent(int $userId, int $zoneId, ?string $zoneType = null): bool
    {
        return $this->permissions->canEditZoneContent($userId, $zoneId, $zoneType);
    }

    public function canEditZoneRecord(int $userId, int $zoneId, string $recordType, ?string $zoneType = null, ?string $recordName = null, ?string $zoneName = null): bool
    {
        return $this->permissions->canEditZoneRecord($userId, $zoneId, $recordType, $zoneType, $recordName, $zoneName);
    }

    public function canDeleteZone(int $userId, int $zoneId): bool
    {
        return $this->permissions->canDeleteZoneById($userId, $zoneId);
    }

    /**
     * MASTER/NATIVE/SLAVE only: the API does not create catalog kinds.
     */
    public function canCreateZone(int $userId, string $zoneType = 'MASTER'): bool
    {
        if (!in_array(strtoupper($zoneType), ZoneKind::basicValues(), true)) {
            return $this->permissions->isAdmin($userId);
        }

        return $this->permissions->canCreateZone($userId, $zoneType);
    }

    public function canManageDnssec(int $userId, int $zoneId): bool
    {
        return $this->permissions->canManageDnssecForZone($userId, $zoneId);
    }

    /**
     * DNSSEC on a zone being created: the caller must end up owning it, directly or via one of the groups.
     */
    public function canManageDnssecForNewZone(int $userId, ?int $ownerId, array $groupIds = []): bool
    {
        if ($this->permissions->isAdmin($userId)) {
            return true;
        }

        if (!$this->permissions->hasPermission($userId, Permission::PERM_ZONE_DNSSEC_MANAGE_OWN)) {
            return false;
        }

        if ($ownerId !== null && $userId === $ownerId) {
            return true;
        }

        if ($groupIds === []) {
            return false;
        }

        $userGroupIds = $this->getUserGroupIds($userId);
        return $userGroupIds !== [] && array_intersect($userGroupIds, $groupIds) !== [];
    }

    public function canViewUser(int $userId, int $targetUserId): bool
    {
        return $userId === $targetUserId || $this->permissions->hasPermission($userId, Permission::PERM_USER_VIEW_OTHERS);
    }

    public function canEditUser(int $userId, int $targetUserId): bool
    {
        if ($this->permissions->isAdmin($userId)) {
            return true;
        }

        // A delegated admin (non-ueberuser) must not modify a ueberuser account.
        if ($userId !== $targetUserId && $this->permissions->isAdmin($targetUserId)) {
            return false;
        }

        if ($userId === $targetUserId && $this->permissions->hasPermission($userId, Permission::PERM_USER_EDIT_OWN)) {
            return true;
        }

        return $this->permissions->hasPermission($userId, Permission::PERM_USER_EDIT_OTHERS);
    }

    public function canEditUserPassword(int $userId, int $targetUserId): bool
    {
        return $userId === $targetUserId || $this->permissions->hasPermission($userId, Permission::PERM_USER_PASSWD_EDIT_OTHERS);
    }

    public function canCreateUser(int $userId): bool
    {
        return $this->permissions->hasPermission($userId, Permission::PERM_USER_ADD_NEW);
    }

    public function canDeleteUser(int $userId, int $targetUserId): bool
    {
        if ($this->permissions->isAdmin($userId)) {
            return true;
        }

        // Neither self-deletion nor deleting a ueberuser account for delegated admins.
        if ($userId === $targetUserId || $this->permissions->isAdmin($targetUserId)) {
            return false;
        }

        return $this->permissions->hasPermission($userId, Permission::PERM_USER_EDIT_OTHERS);
    }

    public function canManageGroups(int $userId): bool
    {
        return $this->permissions->isAdmin($userId);
    }

    public function canEditPermissionTemplates(int $userId): bool
    {
        return $this->permissions->hasPermission($userId, Permission::PERM_USER_EDIT_TEMPL_PERM);
    }

    /**
     * Read the permission template currently stored on an account.
     *
     * @param int $userId User ID to look up
     * @return ?int Template id, or null when the account is gone or has none
     */
    public function getUserPermissionTemplateId(int $userId): ?int
    {
        $stmt = $this->db->prepare("SELECT perm_templ FROM users WHERE id = :user_id");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $templateId = $stmt->fetchColumn();

        return $templateId === false || $templateId === null ? null : (int)$templateId;
    }

    public function templateGrantsSuperuser(int $permTemplId): bool
    {
        return $this->permissions->templateGrantsUberuser($permTemplId);
    }

    public function checkPermissionTemplateAssignment(int $userId, ?int $targetUserId, int $permTemplId): ?string
    {
        return $this->permissions->checkPermissionTemplateAssignment($userId, $targetUserId, $permTemplId);
    }

    public function canListUsers(int $userId): bool
    {
        return $this->permissions->hasPermission($userId, Permission::PERM_USER_VIEW_OTHERS);
    }

    public function canCreateZoneTemplate(int $userId): bool
    {
        return $this->permissions->hasPermission($userId, Permission::PERM_ZONE_TEMPL_ADD);
    }

    public function canEditZoneTemplate(int $userId): bool
    {
        return $this->permissions->hasPermission($userId, Permission::PERM_ZONE_TEMPL_EDIT);
    }

    /**
     * Template records follow the caller's global edit level; clients may not write SOA/NS/LUA.
     */
    public function canWriteTemplateRecordType(int $userId, string $recordType): bool
    {
        return !Permission::isTemplateRecordTypeRestricted($recordType, $this->permissions->getEditPermissionLevel($userId));
    }

    public function canViewZoneTemplates(int $userId): bool
    {
        foreach ([Permission::PERM_ZONE_TEMPL_ADD, Permission::PERM_ZONE_TEMPL_EDIT, Permission::PERM_ZONE_MASTER_ADD, Permission::PERM_ZONE_SLAVE_ADD] as $permission) {
            if ($this->permissions->hasPermission($userId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function canEditZoneMeta(int $userId, int $zoneId): bool
    {
        return $this->permissions->canEditZoneMeta($userId, $zoneId);
    }

    public function canViewZoneMetadata(int $userId, int $zoneId): bool
    {
        return $this->permissions->canViewZoneMetadata($userId, $zoneId);
    }

    public function canViewZoneOwnership(int $userId, int $zoneId): bool
    {
        return $this->permissions->canViewZoneOwnership($userId, $zoneId);
    }

    /**
     * Change request level narrowed to the zone: "all", "own", or "none".
     */
    public function getChangeRequestPermissionLevelForZone(int $userId, int $zoneId): string
    {
        return $this->permissions->getChangeRequestPermissionLevelForZone($userId, $zoneId);
    }

    /**
     * Change approve level narrowed to the zone: "all", "own", or "none".
     */
    public function getChangeApprovePermissionLevelForZone(int $userId, int $zoneId): string
    {
        return $this->permissions->getChangeApprovePermissionLevelForZone($userId, $zoneId);
    }

    /**
     * How the user's zone changes are handled: written directly, filed as a
     * change request, or refused. With change approval off this is today's
     * edit rule.
     *
     * @return string One of the ChangeApprovalPolicy::MODE_* constants
     */
    public function getChangeApprovalMode(int $userId, int $zoneId): string
    {
        return ChangeApprovalPolicy::mode(
            $this->changeApprovalEnabled(),
            $this->changeApprovalRequiredForAll(),
            $this->permissions->getEditPermissionLevelForZone($userId, $zoneId),
            $this->permissions->getChangeRequestPermissionLevelForZone($userId, $zoneId),
            $this->permissions->userOwnsZone($userId, $zoneId)
        );
    }

    /**
     * Whether the user may approve or reject change requests for the zone.
     */
    public function canReviewChangeRequests(int $userId, int $zoneId): bool
    {
        return ChangeApprovalPolicy::canReview(
            $this->permissions->getChangeApprovePermissionLevelForZone($userId, $zoneId),
            $this->permissions->getEditPermissionLevelForZone($userId, $zoneId),
            $this->permissions->userOwnsZone($userId, $zoneId)
        );
    }

    /**
     * Zones whose change requests the user may review: null for every zone,
     * otherwise the owned zones (or none) that the approve and edit levels cover.
     *
     * @return int[]|null
     */
    public function getReviewableZoneIds(int $userId): ?array
    {
        $approve = $this->permissions->getChangeApprovePermissionLevel($userId);
        $edit = $this->permissions->getEditPermissionLevel($userId);
        if ($approve === 'none' || $edit === 'none') {
            return [];
        }
        if ($approve === 'all' && $edit === 'all') {
            return null;
        }

        return $this->getUserOwnedZoneIds($userId);
    }

    /**
     * Whether a zone deletion has to go through a change request. Deleting
     * stays direct for holders of the delete permission unless every change
     * is reviewed.
     */
    public function zoneDeleteRequiresApproval(int $userId, int $zoneId): bool
    {
        return $this->changeApprovalEnabled()
            && $this->changeApprovalRequiredForAll()
            && $this->canDeleteZone($userId, $zoneId);
    }

    /**
     * Whether the user may file a request to delete the zone: their request
     * level covers it, or every change is reviewed and they could delete it.
     */
    public function canRequestZoneDelete(int $userId, int $zoneId): bool
    {
        if (!$this->changeApprovalEnabled()) {
            return false;
        }
        if ($this->getChangeRequestPermissionLevelForZone($userId, $zoneId) !== 'none') {
            return true;
        }

        return $this->changeApprovalRequiredForAll() && $this->canDeleteZone($userId, $zoneId);
    }

    /**
     * canEditZoneRecord()'s record-type rule for filing a request: SOA/NS/LUA
     * need an edit grant above own_as_client or a request level that covers the
     * zone, except subzone NS records for zone_content_edit_ns_subzone holders.
     */
    public function canRequestZoneRecord(int $userId, int $zoneId, string $recordType, ?string $recordName = null, ?string $zoneName = null): bool
    {
        if (!in_array(strtoupper($recordType), Permission::RESTRICTED_TYPES_FOR_CLIENT, true)) {
            return true;
        }
        if ($this->permissions->hasZoneContentEditPermission($userId, $zoneId)) {
            return true;
        }
        if ($this->getChangeRequestPermissionLevelForZone($userId, $zoneId) !== 'none') {
            return true;
        }

        return Permission::isSubzoneNsRecord($recordType, $recordName, $zoneName)
            && $this->permissions->hasPermission($userId, Permission::PERM_EDIT_NS_SUBZONE);
    }

    /**
     * Zones the user owns directly or through any group.
     *
     * @return int[]
     */
    public function getUserOwnedZoneIds(int $userId): array
    {
        $canonicalId = CanonicalZoneSql::canonicalIdColumn();
        $stmt = $this->db->prepare("
            SELECT $canonicalId FROM zones WHERE owner = :user_id
            UNION
            SELECT zg.domain_id FROM zones_groups zg
            INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
            WHERE ugm.user_id = :user_id2
        ");
        $stmt->execute([':user_id' => $userId, ':user_id2' => $userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Get all zone IDs that the user is allowed to view (stateless)
     *
     * @param int $userId User ID to check
     * @return int[]|null Array of zone IDs the user can view, or null if user can view all zones
     */
    public function getUserVisibleZoneIds(int $userId): ?array
    {
        // Uberuser can view all zones
        if ($this->userHasPermission($userId, Permission::PERM_USER_IS_UEBERUSER)) {
            return null; // null = all zones
        }

        // User with zone_content_view_others can view all zones
        if ($this->userHasPermission($userId, Permission::PERM_ZONE_CONTENT_VIEW_OTHERS)) {
            return null; // null = all zones
        }

        // User with zone_content_view_own can view only their own zones (direct + group)
        if ($this->userHasPermission($userId, Permission::PERM_ZONE_CONTENT_VIEW_OWN)) {
            return $this->getUserOwnedZoneIds($userId);
        }

        // No view permissions - return empty array
        return [];
    }

    private function changeApprovalEnabled(): bool
    {
        return (bool)$this->config?->get('approval', 'enabled', false);
    }

    private function changeApprovalRequiredForAll(): bool
    {
        return (bool)$this->config?->get('approval', 'require_review_for_all', false);
    }
}
