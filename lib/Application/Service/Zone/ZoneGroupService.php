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

namespace Poweradmin\Application\Service\Zone;

use InvalidArgumentException;
use Poweradmin\Domain\Error\GroupNotFoundException;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipRefusal;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use Poweradmin\Domain\Service\Zone\ZoneLimitBreach;

/**
 * Assigns zones to groups and removes them; the group-based half of zone ownership.
 *
 * Handles assigning groups as zone owners
 */
class ZoneGroupService
{
    private const SHARED_ZONE_ID = 'Groups cannot be assigned to this zone: its ID is shared with another zone';

    private ZoneGroupRepositoryInterface $zoneGroupRepository;
    private UserGroupLookupInterface $groupRepository;
    private ZoneOwnershipGuard $ownershipGuard;
    private ?ZoneOwnershipLimit $ownershipLimit;

    /**
     * @param ZoneOwnershipLimit|null $ownershipLimit Zone limit of the group being granted a zone; null enforces none
     */
    public function __construct(
        ZoneGroupRepositoryInterface $zoneGroupRepository,
        UserGroupLookupInterface $groupRepository,
        ZoneOwnershipGuard $ownershipGuard,
        ?ZoneOwnershipLimit $ownershipLimit = null
    ) {
        $this->zoneGroupRepository = $zoneGroupRepository;
        $this->groupRepository = $groupRepository;
        $this->ownershipGuard = $ownershipGuard;
        $this->ownershipLimit = $ownershipLimit;
    }

    /**
     * Add a group as zone owner
     *
     * @param int $domainId Zone/Domain ID
     * @param int $groupId Group ID
     * @return ZoneGroup|ZoneLimitBreach The grant, or the group's zone limit it would exceed
     * @throws InvalidArgumentException If group not found or ownership already exists
     */
    public function addGroupToZone(int $domainId, int $groupId): ZoneGroup|ZoneLimitBreach
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
        if ($this->ownershipGuard->refusesNewGrants($domainId)) {
            throw new InvalidArgumentException(self::SHARED_ZONE_ID);
        }
        $write = fn(): ZoneGroup => $this->addGrantOnce($domainId, $groupId);

        return $this->ownershipLimit !== null ? $this->ownershipLimit->addGroupOwner($groupId, $write, $domainId) : $write();
    }

    /**
     * Adds the grant unless a concurrent request already did; run under the zone limit's lock.
     *
     * @throws InvalidArgumentException When the group already owns the zone
     */
    private function addGrantOnce(int $domainId, int $groupId): ZoneGroup
    {
        if ($this->zoneGroupRepository->exists($domainId, $groupId)) {
            throw new InvalidArgumentException('Group already owns this zone');
        }

        return $this->zoneGroupRepository->add($domainId, $groupId);
    }

    /**
     * Remove a group from zone owners. The guard answers before the group
     * lookup so a refusal outranks a missing group, as the API contract orders them.
     *
     * @param int $domainId Zone/Domain ID
     * @param int $groupId Group ID
     * @return bool|ZoneOwnershipRefusal True when removed, false when the group did not own the zone, the refusal when the last-owner rule forbids it
     * @throws GroupNotFoundException If group not found
     */
    public function removeGroupFromZone(int $domainId, int $groupId): bool|ZoneOwnershipRefusal
    {
        $refusal = $this->ownershipGuard->refuseGroupRemoval($domainId, $groupId);
        if ($refusal !== null) {
            return $refusal;
        }

        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new GroupNotFoundException('Group not found');
        }

        // The guard decides again under its own lock; the check above only fixes
        // the order in which a refusal and a missing group are reported.
        return $this->ownershipGuard->removeGroup($domainId, $groupId);
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
     * @return array{success: int[], failed: array<int, string>, limit?: ZoneLimitBreach} Results of bulk operation; limit is set when the group's zone limit stopped it
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
                if ($this->ownershipGuard->refusesNewGrants($domainId)) {
                    $results['failed'][$domainId] = self::SHARED_ZONE_ID;
                } elseif (!$this->zoneGroupRepository->exists($domainId, $groupId)) {
                    // Re-counted per zone, so the run stops at the limit and reports the rest
                    $write = fn(): ZoneGroup => $this->addGrantOnce($domainId, $groupId);
                    $added = $this->ownershipLimit !== null ? $this->ownershipLimit->addGroupOwner($groupId, $write, $domainId) : $write();
                    if ($added instanceof ZoneLimitBreach) {
                        $results['failed'][$domainId] = $added->message();
                        $results['limit'] = $added;
                        continue;
                    }
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
                $outcome = $this->ownershipGuard->removeGroup($domainId, $groupId);
                if ($outcome instanceof ZoneOwnershipRefusal) {
                    $results['failed'][$domainId] = self::refusalReason($outcome);
                } elseif ($outcome) {
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
