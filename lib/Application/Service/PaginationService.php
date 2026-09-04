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
use Poweradmin\Domain\Model\Pagination;
use Poweradmin\Domain\Service\UserPreferenceService;

class PaginationService
{
    /** Lower and upper bounds for a usable page size; shared with UserPreferenceService. */
    public const MIN_ROWS_PER_PAGE = 5;
    public const MAX_ROWS_PER_PAGE = 500;

    /** Offered in the page-size dropdowns; any value within the bounds is still honoured. */
    public const ROWS_PER_PAGE_PRESETS = [10, 20, 50, 100];

    /** Used when nothing usable was supplied; matches interface.rows_per_page's default. */
    public const DEFAULT_ROWS_PER_PAGE = 10;

    private ?UserPreferenceService $userPreferenceService;

    public function __construct(?UserPreferenceService $userPreferenceService = null)
    {
        $this->userPreferenceService = $userPreferenceService;
    }

    /**
     * Create a pagination object with proper validation
     */
    public function createPagination(int $totalItems, int $itemsPerPage, int $currentPage): Pagination
    {
        // Validate and sanitize items per page
        $itemsPerPage = $this->getValidatedItemsPerPage($itemsPerPage);

        // Validate current page
        $currentPage = max(1, min($currentPage, (int) ceil($totalItems / $itemsPerPage)));

        return new Pagination($totalItems, $itemsPerPage, $currentPage);
    }

    /**
     * Get user preference for items per page with validation
     *
     * @param int $defaultRowsPerPage Default rows per page from config
     * @param int|null $userId User ID to get preferences for
     * @return int Validated rows per page value
     */
    public function getUserRowsPerPage(int $defaultRowsPerPage, ?int $userId = null): int
    {
        // Check if user has specified a preference via URL
        $userRowsPerPage = isset($_GET['rows_per_page']) ? (int)$_GET['rows_per_page'] : null;

        // If URL parameter is set, update user preference
        if ($userRowsPerPage !== null && $userId !== null && $this->userPreferenceService !== null) {
            try {
                $this->userPreferenceService->setRowsPerPage($userId, $userRowsPerPage);
            } catch (InvalidArgumentException $e) {
                // Invalid value, ignore and continue
            }
        }

        // Try to get from user preferences first
        if ($userId !== null && $this->userPreferenceService !== null && $userRowsPerPage === null) {
            $userRowsPerPage = $this->userPreferenceService->getRowsPerPage($userId);
        }

        return $this->getValidatedItemsPerPage($userRowsPerPage ?? $defaultRowsPerPage);
    }

    /**
     * Clamp the requested page size into the supported range. A configured or stored
     * value outside the presets is honoured rather than silently replaced.
     */
    private function getValidatedItemsPerPage(?int $itemsPerPage): int
    {
        if ($itemsPerPage === null) {
            return self::DEFAULT_ROWS_PER_PAGE;
        }

        if ($itemsPerPage < self::MIN_ROWS_PER_PAGE) {
            return self::MIN_ROWS_PER_PAGE;
        }

        return min($itemsPerPage, self::MAX_ROWS_PER_PAGE);
    }

    /**
     * Page sizes to offer, so the current and configured values are always selectable.
     *
     * @return int[]
     */
    public function getRowsPerPageOptions(int ...$extra): array
    {
        $options = self::ROWS_PER_PAGE_PRESETS;
        foreach ($extra as $value) {
            if ($value >= self::MIN_ROWS_PER_PAGE && $value <= self::MAX_ROWS_PER_PAGE) {
                $options[] = $value;
            }
        }

        $options = array_values(array_unique($options));
        sort($options);

        return $options;
    }
}
