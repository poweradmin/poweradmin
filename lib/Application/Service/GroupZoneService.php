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
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

/**
 * Service for managing group zone ownership
 *
 * Handles adding/removing zones to/from groups
 */
class GroupZoneService
{
    private ZoneGroupRepositoryInterface $zoneRepository;
    private UserGroupRepositoryInterface $groupRepository;

    public function __construct(
        ZoneGroupRepositoryInterface $zoneRepository,
        UserGroupRepositoryInterface $groupRepository
    ) {
        $this->zoneRepository = $zoneRepository;
        $this->groupRepository = $groupRepository;
    }

    /**
     * Add a zone to a group
     *
     * @param int $groupId Group ID
     * @param int $domainId Domain/Zone ID
     * @return ZoneGroup
     * @throws InvalidArgumentException If group not found or ownership already exists
     */
    public function addZoneToGroup(int $groupId, int $domainId): ZoneGroup
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        // Check if ownership already exists
        if ($this->zoneRepository->exists($domainId, $groupId)) {
            throw new InvalidArgumentException('Zone is already owned by this group');
        }

        return $this->zoneRepository->add($domainId, $groupId);
    }

    /**
     * Remove a zone from a group
     *
     * Permission effect: Group members immediately lose permissions on this zone
     *
     * @param int $groupId Group ID
     * @param int $domainId Domain/Zone ID
     * @return bool
     * @throws InvalidArgumentException If group not found
     */
    public function removeZoneFromGroup(int $groupId, int $domainId): bool
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        return $this->zoneRepository->remove($domainId, $groupId);
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
            throw new InvalidArgumentException('Group not found');
        }

        return $this->zoneRepository->findByGroupId($groupId);
    }

    /**
     * List all groups that own a zone
     *
     * @param int $domainId Domain/Zone ID
     * @return ZoneGroup[]
     */
    public function listZoneGroups(int $domainId): array
    {
        return $this->zoneRepository->findByDomainId($domainId);
    }

    /**
     * Add multiple zones to a group
     *
     * @param int $groupId Group ID
     * @param int[] $domainIds Array of domain/zone IDs
     * @return array{success: int[], failed: array<int, string>} Results of bulk operation
     */
    public function bulkAddZones(int $groupId, array $domainIds): array
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($domainIds as $domainId) {
            try {
                if (!$this->zoneRepository->exists($domainId, $groupId)) {
                    $this->zoneRepository->add($domainId, $groupId);
                    $results['success'][] = $domainId;
                } else {
                    $results['failed'][$domainId] = 'Already owned by this group';
                }
            } catch (\Exception $e) {
                $results['failed'][$domainId] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Remove multiple zones from a group
     *
     * @param int $groupId Group ID
     * @param int[] $domainIds Array of domain/zone IDs
     * @return array{success: int[], failed: array<int, string>} Results of bulk operation
     */
    public function bulkRemoveZones(int $groupId, array $domainIds): array
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($domainIds as $domainId) {
            try {
                if ($this->zoneRepository->remove($domainId, $groupId)) {
                    $results['success'][] = $domainId;
                } else {
                    $results['failed'][$domainId] = 'Not owned by this group';
                }
            } catch (\Exception $e) {
                $results['failed'][$domainId] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Check if a zone is owned by a group
     *
     * @param int $groupId Group ID
     * @param int $domainId Domain/Zone ID
     * @return bool
     */
    public function isZoneOwnedByGroup(int $groupId, int $domainId): bool
    {
        return $this->zoneRepository->exists($domainId, $groupId);
    }
}
