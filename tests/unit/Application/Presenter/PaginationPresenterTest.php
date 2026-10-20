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

namespace Poweradmin\Tests\Unit\Application\Presenter;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Domain\Model\Pagination;

class PaginationPresenterTest extends TestCase
{
    private const ELLIPSIS = '<li class="page-item disabled"><span class="page-link">..</span></li>';

    private function presenter(int $totalItems, int $currentPage, ?int $rowsPerPage = null): PaginationPresenter
    {
        return new PaginationPresenter(new Pagination($totalItems, 10, $currentPage), '/zones/forward?start={PageNumber}', $rowsPerPage);
    }

    private function link(int $page, string $text, bool $active = false): string
    {
        $activeClass = $active ? ' active' : '';
        return "<li class=\"page-item$activeClass\"><a class=\"page-link\" href=\"/zones/forward?start=$page\">$text</a></li>";
    }

    public function testSinglePageRendersNothing(): void
    {
        $this->assertSame('', $this->presenter(10, 1)->present());
        $this->assertSame('', $this->presenter(0, 1)->present());
    }

    public function testFirstPageOfShortListHasNoPreviousLinkAndNoEllipsis(): void
    {
        $expected = '<nav><ul class="pagination pagination-sm d-flex flex-wrap">'
            . $this->link(1, '1', true) . $this->link(2, '2') . $this->link(3, '3')
            . $this->link(2, 'Next')
            . '</ul></nav>';

        $this->assertSame($expected, $this->presenter(25, 1)->present());
    }

    public function testMiddlePageShowsFirstAndLastWithEllipsesAroundTheWindow(): void
    {
        $expected = '<nav><ul class="pagination pagination-sm d-flex flex-wrap">'
            . $this->link(14, 'Previous')
            . $this->link(1, '1') . self::ELLIPSIS;
        for ($i = 11; $i <= 18; $i++) {
            $expected .= $this->link($i, (string)$i, $i === 15);
        }
        $expected .= self::ELLIPSIS . $this->link(30, '30')
            . $this->link(16, 'Next')
            . '</ul></nav>';

        $this->assertSame($expected, $this->presenter(300, 15)->present());
    }

    public function testLastPageHasNoNextLinkAndNoTrailingEllipsis(): void
    {
        $expected = '<nav><ul class="pagination pagination-sm d-flex flex-wrap">'
            . $this->link(29, 'Previous')
            . $this->link(1, '1') . self::ELLIPSIS;
        for ($i = 26; $i <= 30; $i++) {
            $expected .= $this->link($i, (string)$i, $i === 30);
        }
        $expected .= '</ul></nav>';

        $this->assertSame($expected, $this->presenter(300, 30)->present());
    }

    public function testEllipsisIsSkippedWhenTheWindowIsAdjacentToTheFirstOrLastPage(): void
    {
        // Page 6 of 10: window 2..9, so page 1 and page 10 are both adjacent to it
        $html = $this->presenter(100, 6)->present();

        $this->assertStringNotContainsString(self::ELLIPSIS, $html);
        $this->assertStringContainsString($this->link(1, '1') . $this->link(2, '2'), $html);
        $this->assertStringContainsString($this->link(9, '9') . $this->link(10, '10'), $html);
    }

    public function testRowsPerPageIsAppendedToEveryUrl(): void
    {
        $html = $this->presenter(25, 2, 50)->present();

        $this->assertStringContainsString('href="/zones/forward?start=1&rows_per_page=50"', $html);
        $this->assertStringContainsString('href="/zones/forward?start=3&rows_per_page=50"', $html);
    }

    public function testRowsPerPageStartsTheQueryStringWhenThePatternHasNone(): void
    {
        $presenter = new PaginationPresenter(new Pagination(25, 10, 1), '/users/{PageNumber}', 20);

        $this->assertStringContainsString('href="/users/2?rows_per_page=20"', $presenter->present());
    }
}
