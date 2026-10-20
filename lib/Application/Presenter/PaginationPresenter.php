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

namespace Poweradmin\Application\Presenter;

use Poweradmin\Domain\Model\Pagination;

/**
 * Describes the page-number list for a Pagination object, with ellipses around the current page.
 *
 * items() feeds templates/default/_partials/pagination.html; present() is the pre-rendered
 * string still exposed as the `pagination` template variable for theme forks.
 */
class PaginationPresenter
{
    private Pagination $pagination;

    private string $urlPattern;
    private ?int $rowsPerPage;

    private int $numDisplayPages = 8;

    public function __construct(Pagination $pagination, string $urlPattern, ?int $rowsPerPage = null)
    {
        $this->pagination = $pagination;
        $this->urlPattern = $urlPattern;
        $this->rowsPerPage = $rowsPerPage;
    }

    /**
     * Previous, first page, leading ellipsis, the window around the current page,
     * trailing ellipsis, last page and next, in display order. Empty for a single page.
     *
     * @return list<array{page: ?int, label: string, url: ?string, active: bool, ellipsis: bool}>
     */
    public function items(): array
    {
        $numberOfPages = $this->pagination->getNumberOfPages();
        if ($numberOfPages <= 1) {
            return [];
        }

        $items = [];

        if ($this->pagination->hasPreviousPage()) {
            $items[] = $this->pageItem($this->pagination->getPreviousPage(), _('Previous'), false);
        }

        $currentPage = $this->pagination->getCurrentPage();
        $startPage = max($currentPage - (int)($this->numDisplayPages / 2), 1);
        $endPage = min($startPage + $this->numDisplayPages - 1, $numberOfPages);

        if ($startPage > 1) {
            $items[] = $this->pageItem(1, '1', false);
            if ($startPage > 2) {
                $items[] = $this->ellipsisItem();
            }
        }

        for ($i = $startPage; $i <= $endPage; $i++) {
            $items[] = $this->pageItem($i, (string)$i, $i === $currentPage);
        }

        if ($endPage < $numberOfPages) {
            if ($endPage < $numberOfPages - 1) {
                $items[] = $this->ellipsisItem();
            }
            $items[] = $this->pageItem($numberOfPages, (string)$numberOfPages, false);
        }

        if ($this->pagination->hasNextPage()) {
            $items[] = $this->pageItem($this->pagination->getNextPage(), _('Next'), false);
        }

        return $items;
    }

    public function present(): string
    {
        $items = $this->items();
        if ($items === []) {
            return '';
        }

        $html = '<nav><ul class="pagination pagination-sm d-flex flex-wrap">';

        foreach ($items as $item) {
            if ($item['ellipsis']) {
                $html .= '<li class="page-item disabled"><span class="page-link">' . $item['label'] . '</span></li>';
                continue;
            }
            $activeClass = $item['active'] ? ' active' : '';
            $html .= "<li class=\"page-item$activeClass\"><a class=\"page-link\" href=\"{$item['url']}\">{$item['label']}</a></li>";
        }

        return $html . '</ul></nav>';
    }

    /**
     * @return array{page: int, label: string, url: string, active: bool, ellipsis: false}
     */
    private function pageItem(int $pageNumber, string $label, bool $isActive): array
    {
        return [
            'page' => $pageNumber,
            'label' => $label,
            'url' => $this->createPageUrl($pageNumber),
            'active' => $isActive,
            'ellipsis' => false,
        ];
    }

    private function createPageUrl(int $pageNumber): string
    {
        $url = str_replace('{PageNumber}', (string)$pageNumber, $this->urlPattern);

        if ($this->rowsPerPage !== null) {
            $url .= (parse_url($url, PHP_URL_QUERY) ? '&' : '?') . 'rows_per_page=' . $this->rowsPerPage;
        }

        return $url;
    }

    /**
     * @return array{page: null, label: string, url: null, active: false, ellipsis: true}
     */
    private function ellipsisItem(): array
    {
        return ['page' => null, 'label' => '..', 'url' => null, 'active' => false, 'ellipsis' => true];
    }
}
