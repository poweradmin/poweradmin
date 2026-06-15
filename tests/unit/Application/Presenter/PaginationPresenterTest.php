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

namespace unit\Application\Presenter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Domain\Model\Pagination;

#[CoversClass(PaginationPresenter::class)]
class PaginationPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET['rows_per_page']);
    }

    #[Test]
    public function preservesQueryParamsAcrossPageLinks(): void
    {
        $pagination = new Pagination(100, 10, 2);
        $presenter = new PaginationPresenter($pagination, '/zones/logs?start={PageNumber}', '', ['zone_id' => 5]);

        $html = $presenter->present();

        $this->assertStringContainsString('/zones/logs?start=1&zone_id=5', $html);
        $this->assertStringContainsString('/zones/logs?start=3&zone_id=5', $html);
    }

    #[Test]
    public function withoutQueryParamsLinksAreUnchanged(): void
    {
        $pagination = new Pagination(100, 10, 2);
        $presenter = new PaginationPresenter($pagination, '/zones/logs?start={PageNumber}');

        $html = $presenter->present();

        $this->assertStringContainsString('/zones/logs?start=3', $html);
        $this->assertStringNotContainsString('zone_id', $html);
    }

    #[Test]
    public function urlEncodesQueryParamValues(): void
    {
        $pagination = new Pagination(100, 10, 2);
        $presenter = new PaginationPresenter($pagination, '/zones/logs?start={PageNumber}', '', ['name' => 'a b&c']);

        $html = $presenter->present();

        $this->assertStringContainsString('name=a+b%26c', $html);
    }

    #[Test]
    public function usesQuestionMarkWhenUrlPatternHasNoQueryString(): void
    {
        $pagination = new Pagination(100, 10, 2);
        $presenter = new PaginationPresenter($pagination, '/zones/logs/{PageNumber}', '', ['zone_id' => 5]);

        $html = $presenter->present();

        $this->assertStringContainsString('/zones/logs/3?zone_id=5', $html);
    }
}
