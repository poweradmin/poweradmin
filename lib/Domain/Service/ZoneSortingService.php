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
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Domain\Service;

use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;

class ZoneSortingService
{
    private ReverseZoneSorting $reverseZoneSorting;

    public function __construct()
    {
        $this->reverseZoneSorting = new ReverseZoneSorting();
    }

    /**
     * Get zone sort order from session/request
     *
     * @param string $name Parameter name
     * @param array $allowedValues Allowed sort values
     * @return array [sortBy, sortDirection]
     */
    public function getZoneSortOrder(string $name, array $allowedValues): array
    {
        $zone_sort_by = 'name';
        $zone_sort_direction = 'ASC';

        if (isset($_GET[$name]) && preg_match("/^[a-z_]+$/", $_GET[$name])) {
            $zone_sort_by = htmlspecialchars($_GET[$name]);
            $_SESSION['list_zone_sort_by'] = htmlspecialchars($_GET[$name]);
        } elseif (isset($_POST[$name]) && preg_match("/^[a-z_]+$/", $_POST[$name])) {
            $zone_sort_by = htmlspecialchars($_POST[$name]);
            $_SESSION['list_zone_sort_by'] = htmlspecialchars($_POST[$name]);
        } elseif (isset($_SESSION['list_zone_sort_by'])) {
            $zone_sort_by = $_SESSION['list_zone_sort_by'];
        }

        if (!in_array($zone_sort_by, $allowedValues)) {
            $zone_sort_by = 'name';
        }

        if (isset($_GET[$name . '_direction']) && in_array(strtoupper($_GET[$name . '_direction']), ['ASC', 'DESC'])) {
            $zone_sort_direction = strtoupper($_GET[$name . '_direction']);
            $_SESSION['list_zone_sort_by_direction'] = strtoupper($_GET[$name . '_direction']);
        } elseif (isset($_POST[$name . '_direction']) && in_array(strtoupper($_POST[$name . '_direction']), ['ASC', 'DESC'])) {
            $zone_sort_direction = strtoupper($_POST[$name . '_direction']);
            $_SESSION['list_zone_sort_by_direction'] = strtoupper($_POST[$name . '_direction']);
        } elseif (isset($_SESSION['list_zone_sort_by_direction'])) {
            $zone_sort_direction = $_SESSION['list_zone_sort_by_direction'];
        }

        return [$zone_sort_by, $zone_sort_direction];
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
        $reverse_zone_type = 'all';

        if (isset($_GET['reverse_type'])) {
            $reverse_zone_type = htmlspecialchars($_GET['reverse_type']);
            $_SESSION['reverse_zone_type'] = $reverse_zone_type;
        } elseif (isset($_SESSION['reverse_zone_type'])) {
            $reverse_zone_type = $_SESSION['reverse_zone_type'];
        }

        return $reverse_zone_type;
    }
}
