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

namespace Poweradmin\Application\Service;

use PDO;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;

/**
 * Hybrid Permission Resolution Service
 *
 * Merges permissions from multiple sources:
 * 1. Direct user ownership (via zones table)
 * 2. Group membership (via user_group_members + zones_groups)
 *
 * Permission Resolution Logic (OR/Highest Wins):
 * - User gets union of all permissions from all sources
 * - If any source grants a permission, user has that permission
 * - Admin users (überuser) bypass all checks and have all permissions
 */
class HybridPermissionService
{
    private PDO $db;
    private UserGroupRepositoryInterface $groupRepository;
    private UserGroupMemberRepositoryInterface $memberRepository;
    private ZoneGroupRepositoryInterface $zoneGroupRepository;

    public function __construct(
        PDO $db,
        UserGroupRepositoryInterface $groupRepository,
        UserGroupMemberRepositoryInterface $memberRepository,
        ZoneGroupRepositoryInterface $zoneGroupRepository
    ) {
        $this->db = $db;
        $this->groupRepository = $groupRepository;
        $this->memberRepository = $memberRepository;
        $this->zoneGroupRepository = $zoneGroupRepository;
    }

    /**
     * Get effective permissions for a user on a specific zone
     *
     * Merges permissions from:
     * - Direct user ownership (zones table)
     * - Group ownership (user_group_members + zones_groups)
     *
     * @param int $userId User ID
     * @param int $domainId Zone/Domain ID
     * @return array{permissions: string[], sources: array} Effective permissions and their sources
     */
    public function getUserPermissionsForZone(int $userId, int $domainId): array
    {
        $allPermissions = [];
        $sources = [];

        // Source 1: Direct user ownership
        $directPermissions = $this->getDirectUserPermissions($userId, $domainId);
        if (!empty($directPermissions)) {
            $allPermissions = array_merge($allPermissions, $directPermissions);
            $sources[] = [
                'type' => 'user',
                'id' => $userId,
                'permissions' => $directPermissions
            ];
        }

        // Source 2: Group memberships
        $groupPermissions = $this->getGroupPermissions($userId, $domainId);
        foreach ($groupPermissions as $groupPerm) {
            $allPermissions = array_merge($allPermissions, $groupPerm['permissions']);
            $sources[] = [
                'type' => 'group',
                'id' => $groupPerm['group_id'],
                'name' => $groupPerm['group_name'],
                'permissions' => $groupPerm['permissions']
            ];
        }

        // Remove duplicates and return union of all permissions
        $effectivePermissions = array_unique($allPermissions);

        return [
            'permissions' => array_values($effectivePermissions),
            'sources' => $sources
        ];
    }

    /**
     * Check if user can perform a specific action on a zone
     *
     * @param int $userId User ID
     * @param int $domainId Zone/Domain ID
     * @param string $permissionName Permission name (e.g., 'zone_content_edit_own')
     * @return bool
     */
    public function canUserPerformAction(int $userId, int $domainId, string $permissionName): bool
    {
        $result = $this->getUserPermissionsForZone($userId, $domainId);
        return in_array($permissionName, $result['permissions']);
    }

    /**
     * Check if user owns a zone (directly or via group)
     *
     * @param int $userId User ID
     * @param int $domainId Zone/Domain ID
     * @return bool
     */
    public function isUserZoneOwner(int $userId, int $domainId): bool
    {
        $result = $this->getUserPermissionsForZone($userId, $domainId);
        return !empty($result['permissions']);
    }

    /**
     * Get all zones accessible by user (direct + group ownership)
     *
     * @param int $userId User ID
     * @return array{user_zones: int[], group_zones: int[]} Arrays of domain IDs
     */
    public function getUserAccessibleZones(int $userId): array
    {
        // Direct user zones
        $query = "SELECT DISTINCT domain_id FROM zones WHERE owner = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        $userZones = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Group zones (via membership)
        $query = "SELECT DISTINCT zg.domain_id
                  FROM zones_groups zg
                  INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                  WHERE ugm.user_id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        $groupZones = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return [
            'user_zones' => array_map('intval', $userZones),
            'group_zones' => array_map('intval', $groupZones)
        ];
    }

    /**
     * Get debug information about permission sources for a zone
     *
     * Useful for troubleshooting and displaying to admins
     *
     * @param int $userId User ID
     * @param int $domainId Zone/Domain ID
     * @return array Detailed permission information
     */
    public function getPermissionSources(int $userId, int $domainId): array
    {
        return $this->getUserPermissionsForZone($userId, $domainId);
    }

    /**
     * Get direct user permissions from zones table
     *
     * @param int $userId User ID
     * @param int $domainId Zone/Domain ID
     * @return string[] Array of permission names
     */
    private function getDirectUserPermissions(int $userId, int $domainId): array
    {
        $query = "SELECT pi.name
                  FROM zones z
                  INNER JOIN perm_templ pt ON z.zone_templ_id = pt.id
                  INNER JOIN perm_templ_items pti ON pt.id = pti.templ_id
                  INNER JOIN perm_items pi ON pti.perm_id = pi.id
                  WHERE z.owner = :user_id AND z.domain_id = :domain_id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':user_id' => $userId,
            ':domain_id' => $domainId
        ]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get permissions from all groups the user belongs to that own the zone
     *
     * @param int $userId User ID
     * @param int $domainId Zone/Domain ID
     * @return array Array of group permissions with group info
     */
    private function getGroupPermissions(int $userId, int $domainId): array
    {
        $query = "SELECT
                    ug.id as group_id,
                    ug.name as group_name,
                    pi.name as permission
                  FROM user_group_members ugm
                  INNER JOIN user_groups ug ON ugm.group_id = ug.id
                  INNER JOIN zones_groups zg ON ug.id = zg.group_id
                  INNER JOIN perm_templ pt ON ug.perm_templ = pt.id
                  INNER JOIN perm_templ_items pti ON pt.id = pti.templ_id
                  INNER JOIN perm_items pi ON pti.perm_id = pi.id
                  WHERE ugm.user_id = :user_id AND zg.domain_id = :domain_id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':user_id' => $userId,
            ':domain_id' => $domainId
        ]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by group_id
        $groupedResults = [];
        foreach ($results as $row) {
            $groupId = $row['group_id'];
            if (!isset($groupedResults[$groupId])) {
                $groupedResults[$groupId] = [
                    'group_id' => $groupId,
                    'group_name' => $row['group_name'],
                    'permissions' => []
                ];
            }
            $groupedResults[$groupId]['permissions'][] = $row['permission'];
        }

        return array_values($groupedResults);
    }
}
