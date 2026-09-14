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

/**
 * Service for managing zone sorting functionality
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Domain\Service;

use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;
use Poweradmin\Domain\Enum\SortDirection;
use Poweradmin\Domain\Enum\ReverseZoneFilter;

class ZoneSortingService
{
    private ReverseZoneSorting $reverseZoneSorting;
    private UserContextService $userContextService;

    public function __construct(?UserContextService $userContextService = null)
    {
        $this->reverseZoneSorting = new ReverseZoneSorting();
        $this->userContextService = $userContextService ?? new UserContextService();
    }

    /**
     * Get zone sort order from session/submitted values. Callers pass distinct
     * $sessionKey values (see {@see SessionKeys}) to keep buckets isolated.
     *
     * @param array $allowedValues Allowed sort values
     * @param string $sessionKey Session bucket (direction stored under $sessionKey . '_direction')
     * @param string $defaultSortBy Fallback sort column when nothing valid is supplied
     * @param mixed $submittedSortBy Sort column as submitted (POST first, then GET); anything but a string is ignored
     * @param mixed $submittedDirection Sort direction as submitted; anything but a string is ignored
     * @return array [sortBy, sortDirection]
     */
    public function getZoneSortOrder(
        array $allowedValues,
        string $sessionKey = SessionKeys::LIST_ZONE_SORT_BY,
        string $defaultSortBy = 'name',
        mixed $submittedSortBy = null,
        mixed $submittedDirection = null
    ): array {
        $directionSessionKey = $sessionKey . '_direction';

        $zone_sort_by = $this->resolveSortBy($submittedSortBy, $sessionKey)
            ?? $this->userContextService->getSessionData($sessionKey)
            ?? $defaultSortBy;

        if (!in_array($zone_sort_by, $allowedValues)) {
            $zone_sort_by = $defaultSortBy;
        }

        $zone_sort_direction = $this->resolveSortDirection($submittedDirection, $directionSessionKey)
            ?? $this->userContextService->getSessionData($directionSessionKey)
            ?? 'ASC';

        return [$zone_sort_by, $zone_sort_direction];
    }

    private function resolveSortBy(mixed $submittedValue, string $sessionKey): ?string
    {
        if (is_string($submittedValue) && preg_match("/^[a-z_]+$/", $submittedValue)) {
            $value = htmlspecialchars($submittedValue);
            $this->userContextService->setSessionData($sessionKey, $value);
            return $value;
        }
        return null;
    }

    private function resolveSortDirection(mixed $submittedValue, string $sessionKey): ?string
    {
        // tryFrom rather than fromRequest: null here means "nothing supplied",
        // which the caller distinguishes from an explicit direction.
        $direction = is_string($submittedValue) ? SortDirection::tryFrom(strtoupper($submittedValue)) : null;
        if ($direction !== null) {
            $this->userContextService->setSessionData($sessionKey, $direction->value);
            return $direction->value;
        }
        return null;
    }

    /**
     * Apply client-side sorting to reverse zones when sorting by name
     *
     * @param array $zones Array of zones to sort
     * @param string $sortBy Sort column
     * @param string $sortType Sorting type (natural, etc.)
     * @return array Sorted zones
     */
    public function applySortingToZones(array $zones, string $sortBy, string $sortType): array
    {
        if ($sortBy !== 'name' || empty($zones)) {
            return $zones;
        }

        // Extract just the names for sorting
        $zone_names = array_map(function ($zone) {
            return $zone['name'];
        }, $zones);

        // Sort the names using the configured sorting method
        $sorted_names = $this->reverseZoneSorting->sortDomains($zone_names, $sortType);

        // Reorder the zones array based on the sorted names
        $sorted_zones = [];
        foreach ($sorted_names as $name) {
            foreach ($zones as $zone) {
                if ($zone['name'] === $name) {
                    $sorted_zones[] = $zone;
                    break;
                }
            }
        }

        return $sorted_zones;
    }

    /**
     * Get reverse zone type filter from a submitted value, falling back to session.
     *
     * @param mixed $submittedType Filter value as submitted; anything but a string is ignored
     * @return string
     */
    public function getReverseZoneTypeFilter(mixed $submittedType = null): string
    {
        // tryFrom, not fromRequest: an unknown request value must leave a valid
        // stored filter alone rather than resetting it to ALL.
        $filter = is_string($submittedType) ? ReverseZoneFilter::tryFrom($submittedType) : null;

        if ($filter !== null) {
            $this->userContextService->setSessionData(SessionKeys::REVERSE_ZONE_TYPE, $filter->value);
            return $filter->value;
        }

        // Stored values predate this allowlist, so revalidate rather than trust the session.
        return ReverseZoneFilter::fromRequest(
            $this->userContextService->getSessionData(SessionKeys::REVERSE_ZONE_TYPE)
        )->value;
    }
}
