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

namespace Poweradmin\Tests\Unit\Application\Service\Web;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Web\PaginationService;

class PaginationServiceTest extends TestCase
{
    private PaginationService $service;

    protected function setUp(): void
    {
        $this->service = new PaginationService();
    }

    /**
     * A configured size outside the presets used to be replaced by 10 without a word,
     * so the setting appeared to save and then never took effect.
     */
    public function testAValueOutsideThePresetsIsHonoured(): void
    {
        $pagination = $this->service->createPagination(1000, 25, 1);

        $this->assertEquals(25, $pagination->getItemsPerPage());
    }

    public function testValuesAreClampedToTheSupportedRange(): void
    {
        $this->assertEquals(
            PaginationService::MIN_ROWS_PER_PAGE,
            $this->service->createPagination(1000, 1, 1)->getItemsPerPage()
        );

        $this->assertEquals(
            PaginationService::MAX_ROWS_PER_PAGE,
            $this->service->createPagination(100000, 99999, 1)->getItemsPerPage()
        );
    }

    public function testPresetsAreAlwaysOffered(): void
    {
        $this->assertSame(PaginationService::ROWS_PER_PAGE_PRESETS, $this->service->getRowsPerPageOptions());
    }

    public function testAConfiguredValueJoinsTheOfferedOptionsInOrder(): void
    {
        $this->assertSame([10, 20, 25, 50, 100], $this->service->getRowsPerPageOptions(25));
    }

    public function testAnOutOfRangeExtraIsNotOffered(): void
    {
        $this->assertSame(PaginationService::ROWS_PER_PAGE_PRESETS, $this->service->getRowsPerPageOptions(99999));
    }

    public function testAcceptedRowsPerPageTakesAnyNumberInsideTheRange(): void
    {
        $this->assertSame(37, PaginationService::acceptedRowsPerPage('37'));
        $this->assertSame(PaginationService::MIN_ROWS_PER_PAGE, PaginationService::acceptedRowsPerPage((string)PaginationService::MIN_ROWS_PER_PAGE));
        $this->assertNull(PaginationService::acceptedRowsPerPage(null));
        $this->assertNull(PaginationService::acceptedRowsPerPage('lots'));
        $this->assertNull(PaginationService::acceptedRowsPerPage((string)(PaginationService::MAX_ROWS_PER_PAGE + 1)));
        $this->assertNull(PaginationService::acceptedRowsPerPage('0'));
    }

    public function testPagerWindowCentresOnTheCurrentPageWithBreaks(): void
    {
        $this->assertSame(
            ['total_pages' => 30, 'start_page' => 11, 'end_page' => 19, 'show_leading_break' => true, 'show_trailing_break' => true],
            PaginationService::pagerWindow(300, 10, 15)
        );
        $this->assertSame(
            ['total_pages' => 3, 'start_page' => 1, 'end_page' => 3, 'show_leading_break' => false, 'show_trailing_break' => false],
            PaginationService::pagerWindow(25, 10, 1)
        );
        // The window is not shifted left near the end; the trailing break just disappears.
        $this->assertSame(
            ['total_pages' => 30, 'start_page' => 25, 'end_page' => 30, 'show_leading_break' => true, 'show_trailing_break' => false],
            PaginationService::pagerWindow(300, 10, 29)
        );
    }
}
