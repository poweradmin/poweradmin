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
    private array $allowedRowsPerPage = [10, 20, 50, 100];
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
     * Validate that the requested items per page is one of the allowed values
     */
    private function getValidatedItemsPerPage(?int $itemsPerPage): int
    {
        if ($itemsPerPage === null || !in_array($itemsPerPage, $this->allowedRowsPerPage)) {
            return $this->allowedRowsPerPage[0]; // Default to first allowed value
        }

        return $itemsPerPage;
    }
}
