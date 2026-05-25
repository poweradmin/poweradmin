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
     * Get zone sort order from session/request. Callers pass distinct
     * $sessionKey values (see {@see SessionKeys}) to keep buckets isolated.
     *
     * @param string $name Parameter name read from $_GET/$_POST
     * @param array $allowedValues Allowed sort values
     * @param string $sessionKey Session bucket (direction stored under $sessionKey . '_direction')
     * @param string $defaultSortBy Fallback sort column when nothing valid is supplied
     * @return array [sortBy, sortDirection]
     */
    public function getZoneSortOrder(
        string $name,
        array $allowedValues,
        string $sessionKey = SessionKeys::LIST_ZONE_SORT_BY,
        string $defaultSortBy = 'name'
    ): array {
        $directionSessionKey = $sessionKey . '_direction';

        $zone_sort_by = $this->resolveSortBy($name, $sessionKey)
            ?? $this->userContextService->getSessionData($sessionKey)
            ?? $defaultSortBy;

        if (!in_array($zone_sort_by, $allowedValues)) {
            $zone_sort_by = $defaultSortBy;
        }

        $zone_sort_direction = $this->resolveSortDirection($name . '_direction', $directionSessionKey)
            ?? $this->userContextService->getSessionData($directionSessionKey)
            ?? 'ASC';

        return [$zone_sort_by, $zone_sort_direction];
    }

    private function resolveSortBy(string $name, string $sessionKey): ?string
    {
        // POST first so a fresh form submission overrides any stale `?sort=` left
        // in the URL bar; list views are GET-only so this swap is a no-op for them.
        foreach ([$_POST[$name] ?? null, $_GET[$name] ?? null] as $candidate) {
            if ($candidate !== null && preg_match("/^[a-z_]+$/", $candidate)) {
                $value = htmlspecialchars($candidate);
                $this->userContextService->setSessionData($sessionKey, $value);
                return $value;
            }
        }
        return null;
    }

    private function resolveSortDirection(string $key, string $sessionKey): ?string
    {
        foreach ([$_POST[$key] ?? null, $_GET[$key] ?? null] as $candidate) {
            if ($candidate !== null && in_array(strtoupper($candidate), ['ASC', 'DESC'])) {
                $value = strtoupper($candidate);
                $this->userContextService->setSessionData($sessionKey, $value);
                return $value;
            }
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
     * Get reverse zone type filter from request/session
     *
     * @return string
     */
    public function getReverseZoneTypeFilter(): string
    {
        if (isset($_GET['reverse_type'])) {
            $reverse_zone_type = htmlspecialchars($_GET['reverse_type']);
            $this->userContextService->setSessionData(SessionKeys::REVERSE_ZONE_TYPE, $reverse_zone_type);
            return $reverse_zone_type;
        }

        return $this->userContextService->getSessionData(SessionKeys::REVERSE_ZONE_TYPE) ?? 'all';
    }
}
