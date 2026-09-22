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

namespace Poweradmin\Application\Service\Web;

use InvalidArgumentException;
use Poweradmin\Domain\Model\Pagination;
use Poweradmin\Domain\Model\PaginationLimits;
use Poweradmin\Domain\Service\User\UserPreferenceService;

/**
 * Builds Pagination objects and resolves the rows-per-page value within the allowed bounds.
 */
class PaginationService
{
    public const MIN_ROWS_PER_PAGE = PaginationLimits::MIN_ROWS_PER_PAGE;
    public const MAX_ROWS_PER_PAGE = PaginationLimits::MAX_ROWS_PER_PAGE;

    /** Offered in the page-size dropdowns; any value within the bounds is still honoured. */
    public const ROWS_PER_PAGE_PRESETS = [10, 20, 50, 100];

    public const DEFAULT_ROWS_PER_PAGE = PaginationLimits::DEFAULT_ROWS_PER_PAGE;

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
     * Resolves the page size: the value the request asked for wins and is stored as the
     * user's preference, otherwise the stored preference, otherwise the configured default.
     *
     * @param int $defaultRowsPerPage Default rows per page from config
     * @param int|null $userId User ID to get preferences for
     * @param int|null $requestedRowsPerPage Page size from the query string (Request::getRowsPerPage())
     * @return int Validated rows per page value
     */
    public function getUserRowsPerPage(int $defaultRowsPerPage, ?int $userId, ?int $requestedRowsPerPage): int
    {
        $preferences = $userId !== null ? $this->userPreferenceService : null;

        if ($requestedRowsPerPage !== null) {
            try {
                $preferences?->setRowsPerPage($userId, $requestedRowsPerPage);
            } catch (InvalidArgumentException) {
                // Out-of-range values are clamped below rather than stored
            }
            return $this->getValidatedItemsPerPage($requestedRowsPerPage);
        }

        return $this->getValidatedItemsPerPage($preferences?->getRowsPerPage($userId) ?? $defaultRowsPerPage);
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
     * A submitted page size, when it is a number inside the supported range;
     * any value in range is accepted, not only the presets.
     */
    public static function acceptedRowsPerPage(mixed $submitted): ?int
    {
        if ($submitted === null || !is_numeric($submitted)) {
            return null;
        }
        $rows = (int)$submitted;
        return $rows >= self::MIN_ROWS_PER_PAGE && $rows <= self::MAX_ROWS_PER_PAGE ? $rows : null;
    }

    /**
     * Sliding window of up to nine page links centred on the current page, with
     * break indicators when the window does not touch the first or last page.
     * Deliberately not the Pagination model: that one shifts the window left
     * near the last page, which would change what the search pager renders.
     *
     * @return array{total_pages: int, start_page: int, end_page: int, show_leading_break: bool, show_trailing_break: bool}
     */
    public static function pagerWindow(int $total, int $rowAmount, int $currentPage): array
    {
        $maxVisiblePages = 9;
        $halfVisiblePages = intdiv($maxVisiblePages, 2);
        $totalPages = (int)ceil($total / max(1, $rowAmount));
        $startPage = max(1, $currentPage - $halfVisiblePages);
        $endPage = min($startPage + $maxVisiblePages - 1, $totalPages);

        return [
            'total_pages' => $totalPages,
            'start_page' => $startPage,
            'end_page' => $endPage,
            'show_leading_break' => $currentPage > $halfVisiblePages + 1,
            'show_trailing_break' => $totalPages > $endPage,
        ];
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
