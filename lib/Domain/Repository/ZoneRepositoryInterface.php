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

namespace Poweradmin\Domain\Repository;

interface ZoneRepositoryInterface
{
    public function getDistinctStartingLetters(int $userId, bool $viewOthers): array;

    /**
     * Get all zones with pagination
     *
     * @param int $offset Pagination offset
     * @param int $limit Maximum number of records to return
     * @return array Array of zones
     */
    public function getAllZones(int $offset, int $limit): array;

    /**
     * Get total count of zones
     *
     * @return int Total number of zones
     */
    public function getZoneCount(): int;

    /**
     * Get a zone by ID
     *
     * @param int $zoneId Zone ID
     * @return array|null Zone data if found, null otherwise
     */
    public function getZoneById(int $zoneId): ?array;


    /**
     * Get reverse zones with efficient database-level filtering and pagination
     *
     * @param string $permType Permission type ('all', 'own')
     * @param int $userId User ID (used when permType is 'own')
     * @param string $reverseType Filter by reverse zone type ('all', 'ipv4', 'ipv6')
     * @param int $offset Pagination offset
     * @param int $limit Maximum number of records to return
     * @param string $sortBy Column to sort by
     * @param string $sortDirection Sort direction ('ASC' or 'DESC')
     * @param bool $countOnly If true, returns only the count of matching zones
     * @return array|int Array of reverse zones or count if countOnly is true
     */
    public function getReverseZones(
        string $permType,
        int $userId,
        string $reverseType = 'all',
        int $offset = 0,
        int $limit = 25,
        string $sortBy = 'name',
        string $sortDirection = 'ASC',
        bool $countOnly = false,
        bool $showSerial = false,
        bool $showTemplate = false
    );

    /**
     * Get domain name by ID
     *
     * @param int $zoneId The zone ID
     * @return string|null The domain name or null if not found
     */
    public function getDomainNameById(int $zoneId): ?string;

    /**
     * Find forward zones associated with reverse zones through PTR records
     *
     * @param array $reverseZoneIds Array of reverse zone IDs
     * @return array Array of PTR record matches with forward zone information
     */
    public function findForwardZonesByPtrRecords(array $reverseZoneIds): array;

    /**
     * Check if zone exists by ID
     *
     * @param int $zoneId The zone ID
     * @return bool True if zone exists
     */
    public function zoneIdExists(int $zoneId): bool;

    /**
     * Get domain type by zone ID
     *
     * @param int $zoneId The zone ID
     * @return string The domain type (MASTER, SLAVE, NATIVE)
     */
    public function getDomainType(int $zoneId): string;

    /**
     * Get slave master by zone ID
     *
     * @param int $zoneId The zone ID
     * @return string|null The slave master or null if not found
     */
    public function getDomainSlaveMaster(int $zoneId): ?string;

    /**
     * Get zone comment by zone ID
     *
     * @param int $zoneId The zone ID
     * @return string|null The zone comment or null if not found
     */
    public function getZoneComment(int $zoneId): ?string;

    /**
     * Update zone comment
     *
     * @param int $zoneId The zone ID
     * @param string $comment The new comment
     * @return bool True if updated successfully
     */
    public function updateZoneComment(int $zoneId, string $comment): bool;

    /**
     * Get users who own a zone
     *
     * @param int $zoneId The zone ID
     * @return array Array of user information
     */
    public function getZoneOwners(int $zoneId): array;

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
     * Get zone ID by name
     *
     * @param string $zoneName The zone name
     * @return int|null The zone ID or null if not found
     */
    public function getZoneIdByName(string $zoneName): ?int;

    /**
     * Update zone metadata
     *
     * @param int $zoneId The zone ID
     * @param array $updates Array of field => value pairs to update
     * @return bool True if zone was updated successfully
     */
    public function updateZone(int $zoneId, array $updates): bool;

    /**
     * Delete a zone by ID
     *
     * @param int $zoneId The zone ID
     * @return bool True if zone was deleted successfully
     */
    public function deleteZone(int $zoneId): bool;

    /**
     * Get a zone by ID with full details
     *
     * @param int $zoneId The zone ID
     * @return array|null The zone data or null if not found
     */
    public function getZone(int $zoneId): ?array;

    /**
     * Check if user is already an owner of the zone
     *
     * @param int $zoneId The zone ID
     * @param int $userId The user ID
     * @return bool True if user is already an owner
     */
    public function isUserZoneOwner(int $zoneId, int $userId): bool;
}
