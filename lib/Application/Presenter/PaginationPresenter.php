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

namespace Poweradmin\Application\Presenter;

use Poweradmin\Domain\Model\Pagination;

class PaginationPresenter
{
    private Pagination $pagination;

    private string $urlPattern;
    private string $id;

    private int $numDisplayPages = 8;

    public function __construct(Pagination $pagination, string $urlPattern, string $id = '')
    {
        $this->pagination = $pagination;
        $this->urlPattern = $urlPattern;
        $this->id = $id;
    }

    public function present(): string
    {
        if ($this->pagination->getNumberOfPages() <= 1) {
            return '';
        }

        $html = '<nav><ul class="pagination">';

        if ($this->pagination->hasPreviousPage()) {
            $html .= $this->pageItem($this->pagination->getPreviousPage(), _('Previous'), false);
        }

        $currentPage = $this->pagination->getCurrentPage();
        $startPage = max($currentPage - (int)($this->numDisplayPages / 2), 1);
        $endPage = min($startPage + $this->numDisplayPages - 1, $this->pagination->getNumberOfPages());

        if ($startPage > 1) {
            $html .= $this->pageItem(1, '1', false);
            if ($startPage > 2) {
                $html .= $this->ellipsisItem();
            }
        }

        for ($i = $startPage; $i <= $endPage; $i++) {
            $isActive = $i === $currentPage;
            $html .= $this->pageItem($i, (string)$i, $isActive);
        }

        if ($endPage < $this->pagination->getNumberOfPages()) {
            if ($endPage < $this->pagination->getNumberOfPages() - 1) {
                $html .= $this->ellipsisItem();
            }
            $html .= $this->pageItem($this->pagination->getNumberOfPages(), (string)$this->pagination->getNumberOfPages(), false);
        }

        if ($this->pagination->hasNextPage()) {
            $html .= $this->pageItem($this->pagination->getNextPage(), _('Next'), false);
        }

        $html .= '</ul></nav>';

        return $html;
    }

    private function pageItem(int $pageNumber, string $text, bool $isActive): string
    {
        $url = $this->createPageUrl($pageNumber);
        $activeClass = $isActive ? ' active' : '';

        return "<li class=\"page-item$activeClass\"><a class=\"page-link\" href=\"$url\">$text</a></li>";
    }

    private function createPageUrl(int $pageNumber): string
    {
        $url = str_replace('{PageNumber}', $pageNumber, $this->urlPattern);
        if ($this->id !== '') {
            $url .= (parse_url($url, PHP_URL_QUERY) ? '&' : '?') . 'id=' . urlencode($this->id);
        }
        return $url;
    }

    private function ellipsisItem(): string
    {
        return '<li class="page-item disabled"><span class="page-link">..</span></li>';
    }
}
