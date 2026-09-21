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

use InvalidArgumentException;
use Poweradmin\Domain\Error\GroupNotFoundException;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipRefusal;

/**
 * Assigns zones to groups and removes them; the group-based half of zone ownership.
 *
 * Handles assigning groups as zone owners
 */
class ZoneGroupService
{
    private ZoneGroupRepositoryInterface $zoneGroupRepository;
    private UserGroupLookupInterface $groupRepository;
    private ZoneOwnershipGuard $ownershipGuard;

    public function __construct(
        ZoneGroupRepositoryInterface $zoneGroupRepository,
        UserGroupLookupInterface $groupRepository,
        ZoneOwnershipGuard $ownershipGuard
    ) {
        $this->zoneGroupRepository = $zoneGroupRepository;
        $this->groupRepository = $groupRepository;
        $this->ownershipGuard = $ownershipGuard;
    }

    /**
     * Add a group as zone owner
     *
     * @param int $domainId Zone/Domain ID
     * @param int $groupId Group ID
     * @return ZoneGroup
     * @throws InvalidArgumentException If group not found or ownership already exists
     */
    public function addGroupToZone(int $domainId, int $groupId): ZoneGroup
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new GroupNotFoundException('Group not found');
        }

        // Check if ownership already exists
        if ($this->zoneGroupRepository->exists($domainId, $groupId)) {
            throw new InvalidArgumentException('Group already owns this zone');
        }

        return $this->zoneGroupRepository->add($domainId, $groupId);
    }

    /**
     * Remove a group from zone owners
     *
     * Note: Zones can exist without any owners
     *
     * @param int $domainId Zone/Domain ID
     * @param int $groupId Group ID
     * @return bool
     * @throws InvalidArgumentException If group not found
     */
    public function removeGroupFromZone(int $domainId, int $groupId): bool
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new GroupNotFoundException('Group not found');
        }

        return $this->zoneGroupRepository->remove($domainId, $groupId);
    }

    /**
     * List all groups that own a zone
     *
     * @param int $domainId Zone/Domain ID
     * @return ZoneGroup[]
     */
    public function listZoneOwners(int $domainId): array
    {
        return $this->zoneGroupRepository->findByDomainId($domainId);
    }

    /**
     * List all zones owned by a group
     *
     * @param int $groupId Group ID
     * @return ZoneGroup[]
     * @throws InvalidArgumentException If group not found
     */
    public function listGroupZones(int $groupId): array
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new GroupNotFoundException('Group not found');
        }

        return $this->zoneGroupRepository->findByGroupId($groupId);
    }

    /**
     * Add multiple zones to a group
     *
     * @param int $groupId Group ID
     * @param int[] $domainIds Array of domain IDs
     * @return array{success: int[], failed: array<int, string>} Results of bulk operation
     */
    public function bulkAddZones(int $groupId, array $domainIds): array
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new GroupNotFoundException('Group not found');
        }

        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($domainIds as $domainId) {
            try {
                if (!$this->zoneGroupRepository->exists($domainId, $groupId)) {
                    $this->zoneGroupRepository->add($domainId, $groupId);
                    $results['success'][] = $domainId;
                } else {
                    $results['failed'][$domainId] = 'Group already owns this zone';
                }
            } catch (\Exception $e) {
                $results['failed'][$domainId] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Remove multiple zones from a group. A zone whose last allowed owner is
     * this group stays assigned and is reported in `failed` with the reason.
     *
     * @param int $groupId Group ID
     * @param int[] $domainIds Array of domain IDs
     * @return array{success: int[], failed: array<int, string>} Results of bulk operation
     */
    public function bulkRemoveZones(int $groupId, array $domainIds): array
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new GroupNotFoundException('Group not found');
        }

        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($domainIds as $domainId) {
            try {
                $refusal = $this->ownershipGuard->refuseGroupRemoval($domainId, $groupId);
                if ($refusal !== null) {
                    $results['failed'][$domainId] = self::refusalReason($refusal);
                } elseif ($this->zoneGroupRepository->remove($domainId, $groupId)) {
                    $results['success'][] = $domainId;
                } else {
                    $results['failed'][$domainId] = 'Group does not own this zone';
                }
            } catch (\Exception $e) {
                $results['failed'][$domainId] = $e->getMessage();
            }
        }

        return $results;
    }

    private static function refusalReason(ZoneOwnershipRefusal $refusal): string
    {
        return match ($refusal->code) {
            ZoneOwnershipRefusal::LAST_GROUP_GROUPS_ONLY => 'Cannot remove the last group: zone ownership mode is groups_only and requires at least one group',
            ZoneOwnershipRefusal::USERS_ONLY_NO_USER_OWNERS => 'Cannot remove group: zone ownership mode is users_only and the zone has no user owners',
            default => 'Cannot remove the last owner: this would leave the zone with no ownership',
        };
    }

    /**
     * Check if a group owns a zone
     *
     * @param int $domainId Zone/Domain ID
     * @param int $groupId Group ID
     * @return bool
     */
    public function isGroupOwner(int $domainId, int $groupId): bool
    {
        return $this->zoneGroupRepository->exists($domainId, $groupId);
    }

    /**
     * Get zones that will be affected when a group is deleted
     *
     * @param int $groupId Group ID
     * @param int $limit Limit number of zones returned (default 20)
     * @return array{zoneCount: int, zones: ZoneGroup[]}
     */
    public function getGroupDeletionImpact(int $groupId, int $limit = 20): array
    {
        $allZones = $this->zoneGroupRepository->findByGroupId($groupId);
        $zoneCount = count($allZones);
        $zones = array_slice($allZones, 0, $limit);

        return [
            'zoneCount' => $zoneCount,
            'zones' => $zones
        ];
    }
}
