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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneSortingService;

#[CoversClass(ZoneSortingService::class)]
class ZoneSortingServiceTest extends TestCase
{
    private ZoneSortingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION = [];
        $_GET = [];
        $_POST = [];

        $this->service = new ZoneSortingService(new UserContextService());
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        parent::tearDown();
    }

    #[Test]
    public function getZoneSortOrderReturnsDefaultsWhenNoInputOrSession(): void
    {
        [$sortBy, $sortDirection] = $this->service->getZoneSortOrder('zone_sort_by', ['name', 'type']);

        $this->assertSame('name', $sortBy);
        $this->assertSame('ASC', $sortDirection);
    }

    #[Test]
    public function getZoneSortOrderPersistsGetParamsToSession(): void
    {
        $_GET['zone_sort_by'] = 'type';
        $_GET['zone_sort_by_direction'] = 'desc';

        [$sortBy, $sortDirection] = $this->service->getZoneSortOrder('zone_sort_by', ['name', 'type']);

        $this->assertSame('type', $sortBy);
        $this->assertSame('DESC', $sortDirection);
        $this->assertSame('type', $_SESSION['list_zone_sort_by']);
        $this->assertSame('DESC', $_SESSION['list_zone_sort_by_direction']);
    }

    #[Test]
    public function getZoneSortOrderFallsBackToSessionWhenNoRequestParam(): void
    {
        $_SESSION['list_zone_sort_by'] = 'type';
        $_SESSION['list_zone_sort_by_direction'] = 'DESC';

        [$sortBy, $sortDirection] = $this->service->getZoneSortOrder('zone_sort_by', ['name', 'type']);

        $this->assertSame('type', $sortBy);
        $this->assertSame('DESC', $sortDirection);
    }

    #[Test]
    public function getZoneSortOrderRejectsValueNotInAllowedList(): void
    {
        $_GET['zone_sort_by'] = 'malicious';

        [$sortBy] = $this->service->getZoneSortOrder('zone_sort_by', ['name', 'type']);

        $this->assertSame('name', $sortBy);
    }

    #[Test]
    public function getZoneSortOrderFallsThroughToPostWhenGetParamIsInvalid(): void
    {
        $_GET['zone_sort_by'] = '!!!invalid!!!';
        $_GET['zone_sort_by_direction'] = 'sideways';
        $_POST['zone_sort_by'] = 'type';
        $_POST['zone_sort_by_direction'] = 'desc';

        [$sortBy, $sortDirection] = $this->service->getZoneSortOrder('zone_sort_by', ['name', 'type']);

        $this->assertSame('type', $sortBy);
        $this->assertSame('DESC', $sortDirection);
    }

    #[Test]
    public function getReverseZoneTypeFilterPersistsGetParamToSession(): void
    {
        $_GET['reverse_type'] = 'ipv4';

        $filter = $this->service->getReverseZoneTypeFilter();

        $this->assertSame('ipv4', $filter);
        $this->assertSame('ipv4', $_SESSION['reverse_zone_type']);
    }

    #[Test]
    public function getReverseZoneTypeFilterReadsFromSessionWhenNoRequestParam(): void
    {
        $_SESSION['reverse_zone_type'] = 'ipv6';

        $this->assertSame('ipv6', $this->service->getReverseZoneTypeFilter());
    }

    #[Test]
    public function getReverseZoneTypeFilterReturnsAllByDefault(): void
    {
        $this->assertSame('all', $this->service->getReverseZoneTypeFilter());
    }
}
