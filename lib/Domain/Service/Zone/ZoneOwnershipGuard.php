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

namespace Poweradmin\Domain\Service\Zone;

use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;

/**
 * The last-owner rule: a zone keeps at least one owner of a kind the ownership
 * mode allows. Every writer that removes a user owner or a group asks here first.
 */
class ZoneOwnershipGuard
{
    public function __construct(
        private readonly ZoneOwnershipRepositoryInterface $zoneRepository,
        private readonly ZoneGroupRepositoryInterface $zoneGroupRepository,
        private readonly ZoneOwnershipModeService $ownershipMode
    ) {
    }

    /**
     * Null when the user may be removed; a refusal otherwise. A user who is not
     * an owner is never refused, so the caller's own not-found path still runs.
     */
    public function refuseUserOwnerRemoval(int $zoneId, int $userId): ?ZoneOwnershipRefusal
    {
        $owners = $this->zoneRepository->getZoneOwners($zoneId);
        $ownerIds = array_map(static fn(array $owner): int => (int)($owner['id'] ?? 0), $owners);
        if (!in_array($userId, $ownerIds, true)) {
            return null;
        }

        $groupCount = count($this->zoneGroupRepository->findByDomainId($zoneId));
        $wouldRemoveLastUserOwner = count($owners) <= 1;

        if ($wouldRemoveLastUserOwner && $groupCount === 0) {
            return $this->refusal(ZoneOwnershipRefusal::LAST_OWNER);
        }
        if ($wouldRemoveLastUserOwner && !$this->ownershipMode->isGroupOwnerAllowed()) {
            return $this->refusal(ZoneOwnershipRefusal::LAST_USER_OWNER_USERS_ONLY);
        }
        if (!$this->ownershipMode->isUserOwnerAllowed() && $groupCount === 0) {
            return $this->refusal(ZoneOwnershipRefusal::GROUPS_ONLY_NO_GROUPS);
        }

        return null;
    }

    /**
     * Null when the group may be removed; a refusal otherwise. A group that does
     * not own the zone is never refused.
     */
    public function refuseGroupRemoval(int $zoneId, int $groupId): ?ZoneOwnershipRefusal
    {
        $groups = $this->zoneGroupRepository->findByDomainId($zoneId);
        $isCurrentGroup = false;
        foreach ($groups as $zoneGroup) {
            if ($zoneGroup->getGroupId() === $groupId) {
                $isCurrentGroup = true;
                break;
            }
        }
        if (!$isCurrentGroup) {
            return null;
        }

        $ownerCount = count($this->zoneRepository->getZoneOwners($zoneId));
        $wouldRemoveLastGroup = count($groups) <= 1;

        if ($wouldRemoveLastGroup && $ownerCount === 0) {
            return $this->refusal(ZoneOwnershipRefusal::LAST_OWNER);
        }
        if ($wouldRemoveLastGroup && !$this->ownershipMode->isUserOwnerAllowed()) {
            return $this->refusal(ZoneOwnershipRefusal::LAST_GROUP_GROUPS_ONLY);
        }
        if (!$this->ownershipMode->isGroupOwnerAllowed() && $ownerCount === 0) {
            return $this->refusal(ZoneOwnershipRefusal::USERS_ONLY_NO_USER_OWNERS);
        }

        return null;
    }

    /**
     * Zones that would be left without an allowed owner if the group were
     * deleted, because deleting it drops its zones_groups rows.
     *
     * @return array<int, string> Zone id => zone name, empty when the group may go
     */
    public function zonesOrphanedByGroupDeletion(int $groupId): array
    {
        $orphaned = [];
        foreach ($this->zoneGroupRepository->findByGroupId($groupId) as $zoneGroup) {
            $domainId = $zoneGroup->getDomainId();
            if ($this->refuseGroupRemoval($domainId, $groupId) !== null) {
                $orphaned[$domainId] = $zoneGroup->getName() ?? ('#' . $domainId);
            }
        }

        return $orphaned;
    }

    private function refusal(string $code): ZoneOwnershipRefusal
    {
        return new ZoneOwnershipRefusal($code, $this->ownershipMode->getMode());
    }
}
