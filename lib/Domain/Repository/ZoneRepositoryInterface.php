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

interface ZoneRepositoryInterface
{
    public function getDistinctStartingLetters(int $userId, bool $viewOthers): array;

    /**
     * Get all zones with pagination
     *
     * @param int|null $offset Pagination offset
     * @param int|null $limit Maximum number of records to return
     * @return array Array of zones
     */
    public function getAllZones(?int $offset = null, ?int $limit = null): array;

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
     * Get all reverse zone counts in a single query (optimization)
     *
     * @param string $permType Permission type ('all', 'own')
     * @param int $userId User ID (used when permType is 'own')
     * @return array{count_all: int, count_ipv4: int, count_ipv6: int}
     */
    public function getReverseZoneCounts(string $permType, int $userId): array;

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

    /**
     * List zones with optional user/permission filtering
     *
     * @param int|null $userId Optional user ID for filtering
     * @param bool $viewOthers Whether user can view other users' zones
     * @param array $filters Optional filters
     * @param int $offset Pagination offset
     * @param int $limit Maximum number of records
     * @return array Array of zones
     */
    public function listZones(?int $userId = null, bool $viewOthers = false, array $filters = [], int $offset = 0, int $limit = 100): array;

    /**
     * Check if a zone exists, optionally for a specific user
     *
     * @param int $zoneId The zone ID
     * @param int|null $userId Optional user ID
     * @return bool True if zone exists
     */
    public function zoneExists(int $zoneId, ?int $userId = null): bool;

    /**
     * Get a zone by name
     *
     * @param string $zoneName The zone name
     * @return array|null Zone data if found, null otherwise
     */
    public function getZoneByName(string $zoneName): ?array;

    /**
     * Create a new domain/zone
     *
     * @param string $domain Domain name
     * @param int $owner Owner user ID
     * @param string $type Zone type (MASTER, SLAVE, NATIVE)
     * @param string $slaveMaster Slave master server
     * @param string $zoneTemplate Zone template name
     * @return bool True if created successfully
     */
    public function createDomain(string $domain, int $owner, string $type, string $slaveMaster = '', string $zoneTemplate = 'none'): bool;

    /**
     * Get count of zones with filtering
     *
     * @param int[]|null $zoneIds Optional array of zone IDs to filter
     * @param int|null $userId Optional user ID filter
     * @param string|null $nameFilter Optional name filter
     * @return int Number of matching zones
     */
    public function getZoneCountFiltered(?array $zoneIds, ?int $userId = null, ?string $nameFilter = null): int;

    /**
     * Get all zones with filtering and pagination
     *
     * @param int[]|null $zoneIds Optional array of zone IDs to filter
     * @param int|null $userId Optional user ID filter
     * @param string|null $nameFilter Optional name filter
     * @param int|null $offset Optional pagination offset
     * @param int|null $limit Optional pagination limit
     * @return array Array of matching zones
     */
    public function getAllZonesFiltered(?array $zoneIds, ?int $userId = null, ?string $nameFilter = null, ?int $offset = null, ?int $limit = null): array;
}
