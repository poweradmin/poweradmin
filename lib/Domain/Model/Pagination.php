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

namespace Poweradmin\Domain\Model;

use InvalidArgumentException;

class Pagination
{
    private int $totalItems;
    private int $itemsPerPage;
    private int $currentPage;
    private int $numberOfPages;

    public function __construct(int $totalItems, int $itemsPerPage, int $currentPage)
    {
        if ($itemsPerPage <= 0) {
            throw new InvalidArgumentException("Items per page must be greater than zero.");
        }

        $this->totalItems = $totalItems;
        $this->itemsPerPage = $itemsPerPage;
        $this->currentPage = $currentPage > 0 ? $currentPage : 1;
        $this->numberOfPages = (int) ceil($totalItems / $itemsPerPage);
    }

    public function getTotalItems(): int
    {
        return $this->totalItems;
    }

    public function getItemsPerPage(): int
    {
        return $this->itemsPerPage;
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getNumberOfPages(): int
    {
        return $this->numberOfPages;
    }

    public function getStartPage(int $numLinks): int
    {
        $half = (int) floor($numLinks / 2);
        if ($this->numberOfPages <= $numLinks || $this->currentPage <= $half) {
            return 1;
        } elseif ($this->currentPage > ($this->numberOfPages - $half)) {
            return $this->numberOfPages - $numLinks + 1;
        } else {
            return $this->currentPage - $half;
        }
    }

    public function getEndPage(int $numLinks): int
    {
        return min($this->getStartPage($numLinks) + $numLinks - 1, $this->numberOfPages);
    }

    public function isFirstPage(): bool
    {
        return $this->currentPage === 1;
    }

    public function isLastPage(): bool
    {
        return $this->currentPage === $this->numberOfPages;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->numberOfPages;
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function getNextPage(): int
    {
        return $this->hasNextPage() ? $this->currentPage + 1 : $this->currentPage;
    }

    public function getPreviousPage(): int
    {
        return $this->hasPreviousPage() ? $this->currentPage - 1 : $this->currentPage;
    }
}
