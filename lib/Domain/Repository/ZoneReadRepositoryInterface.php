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

use Poweradmin\Domain\Model\ZoneDetail;

/**
 * Zone lookups, lists and counts; one of the three roles ZoneRepositoryInterface combines.
 */
interface ZoneReadRepositoryInterface
{
    /**
     * Get zone comment by zone ID
     *
     * @param int $zoneId The zone ID
     * @return string|null The zone comment or null if not found
     */
    public function getZoneComment(int $zoneId): ?string;

    /**
     * Get a zone by ID
     *
     * @param int $zoneId Zone ID
     * @return array|null Zone data if found, null otherwise
     */
    public function getZoneById(int $zoneId): ?array;

    /**
     * Get a zone by ID with full details
     *
     * The getZoneById() core plus comment, secured and the owner list; ZoneDetail::toArray()
     * gives the legacy column-keyed shape.
     *
     * @param int $zoneId The zone ID
     * @return ZoneDetail|null The zone or null if not found
     */
    public function getZone(int $zoneId): ?ZoneDetail;

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

    /**
     * Get the total number of zones
     *
     * @return int Total number of zones
     */
    public function getZoneCount(): int;

    /**
     * Get count of zones with filtering
     *
     * @param int[]|null $zoneIds Optional array of zone IDs to filter
     * @param int|null $userId Optional user ID filter
     * @param string|null $nameFilter Optional name filter
     * @return int Number of matching zones
     */
    public function getZoneCountFiltered(?array $zoneIds, ?int $userId = null, ?string $nameFilter = null): int;

    public function getDistinctStartingLetters(int $userId, bool $viewOthers): array;

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
        bool $showTemplate = false,
        bool $includeHealth = true,
        bool $includeRecordCount = true
    );

    /**
     * Get all reverse zone counts in a single query (optimization)
     *
     * @param string $permType Permission type ('all', 'own')
     * @param int $userId User ID (used when permType is 'own')
     * @return array{count_all: int, count_ipv4: int, count_ipv6: int}
     */
    public function getReverseZoneCounts(string $permType, int $userId): array;

    /**
     * Count the zones a permission scope may see, optionally narrowed by first letter and zone kind.
     *
     * @param string $permType Permission type ('all', 'own'); anything else counts nothing
     * @param int|null $userId User ID for 'own'; null counts nothing
     * @param string $letterStart Single first letter, '1' for a leading digit, or 'all'
     * @param string $zoneType 'forward', 'reverse' or 'all'
     * @return int Number of matching zones
     */
    public function countZones(string $permType, ?int $userId, string $letterStart = 'all', string $zoneType = 'forward'): int;

    /**
     * Find forward zones associated with reverse zones through PTR records
     *
     * @param array $reverseZoneIds Array of reverse zone IDs
     * @return array Array of PTR record matches with forward zone information
     */
    public function findForwardZonesByPtrRecords(array $reverseZoneIds): array;
}
