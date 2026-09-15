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

namespace Poweradmin\Domain\Repository;

/**
 * Zone ownership reads and writes; the ownership half of ZoneRepositoryInterface.
 */
interface ZoneOwnershipRepositoryInterface
{
    /**
     * Whether the zone exists and the user owns it directly or through a group.
     *
     * @param int $zoneId The zone ID
     * @param int $userId The user ID
     * @return bool True if the zone exists and the user owns it directly or via a group
     */
    public function userCanAccessZone(int $zoneId, int $userId): bool;

    /**
     * Get users who own a zone
     *
     * @param int $zoneId The zone ID
     * @return array Array of user information
     */
    public function getZoneOwners(int $zoneId): array;

    /**
     * Direct owners of several zones in one query, for list pages.
     *
     * @param int[] $zoneIds
     * @return array<int, list<int>> Zone id => owner user ids; zones without a row are absent
     */
    public function getOwnerIdsByZoneIds(array $zoneIds): array;

    /**
     * Ids of every zone the user owns directly or through a group, in the id
     * space the zone logs and zones_groups use.
     *
     * @return list<int>
     */
    public function getOwnedZoneIds(int $userId): array;

    /**
     * Add owner to zone
     *
     * @param int $zoneId The zone ID
     * @param int $userId The user ID
     * @return bool True if added successfully
     */
    public function addOwnerToZone(int $zoneId, int $userId): bool;

    /**
     * Remove owner from zone
     *
     * @param int $zoneId The zone ID
     * @param int $userId The user ID
     * @return bool True if removed successfully
     */
    public function removeOwnerFromZone(int $zoneId, int $userId): bool;

    /**
     * Check if user is already an owner of the zone
     *
     * @param int $zoneId The zone ID
     * @param int $userId The user ID
     * @return bool True if user is already an owner
     */
    public function isUserZoneOwner(int $zoneId, int $userId): bool;
}
