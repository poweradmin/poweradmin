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

use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;

/**
 * Builds the ownership index the zone lists and the search page use to decide
 * per-row edit/delete controls and owner-column visibility.
 */
class ZoneListPermissionService
{
    public function __construct(
        private readonly ZoneOwnershipRepositoryInterface $zones,
        private readonly ZoneGroupRepositoryInterface $zoneGroups,
        private readonly UserGroupLookupInterface $userGroups
    ) {
    }

    /**
     * @param int[] $zoneIds The zones on the page; duplicates and zeros are ignored
     */
    public function index(int $userId, array $zoneIds): ZoneOwnershipIndex
    {
        $zoneIds = array_values(array_unique(array_filter(array_map('intval', $zoneIds))));
        if ($zoneIds === []) {
            return new ZoneOwnershipIndex($userId, [], [], []);
        }

        $groupsByZone = $this->zoneGroups->findGroupIdsByDomainIds($zoneIds);
        // Group membership only matters when a listed zone is group-owned
        $userGroupIds = $groupsByZone === [] ? [] : $this->userGroups->getGroupIdsForUser($userId);

        return new ZoneOwnershipIndex(
            $userId,
            $userGroupIds,
            $this->zones->getOwnerIdsByZoneIds($zoneIds),
            $groupsByZone
        );
    }
}
