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
use Poweradmin\Domain\Service\SessionKeys;
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
        $this->assertSame('type', $_SESSION[SessionKeys::LIST_ZONE_SORT_BY]);
        $this->assertSame('DESC', $_SESSION[SessionKeys::LIST_ZONE_SORT_BY . '_direction']);
    }

    #[Test]
    public function getZoneSortOrderFallsBackToSessionWhenNoRequestParam(): void
    {
        $_SESSION[SessionKeys::LIST_ZONE_SORT_BY] = 'type';
        $_SESSION[SessionKeys::LIST_ZONE_SORT_BY . '_direction'] = 'DESC';

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
    public function postSortChoiceTakesPrecedenceOverStaleGetParam(): void
    {
        // Regression: SearchController posts a hidden zone_sort_by field on every
        // header click. If a `?zone_sort_by=...` was ever appended to the URL it
        // must not mask the new POSTed value, or sort headers stop working.
        $_GET['zone_sort_by'] = 'name';
        $_GET['zone_sort_by_direction'] = 'ASC';
        $_POST['zone_sort_by'] = 'type';
        $_POST['zone_sort_by_direction'] = 'desc';

        [$sortBy, $direction] = $this->service->getZoneSortOrder('zone_sort_by', ['name', 'type']);

        $this->assertSame('type', $sortBy);
        $this->assertSame('DESC', $direction);
    }

    #[Test]
    public function getZoneSortOrderHonoursCustomSessionKey(): void
    {
        $_GET['zone_sort_by'] = 'type';
        $_GET['zone_sort_by_direction'] = 'desc';

        [$sortBy, $direction] = $this->service->getZoneSortOrder(
            'zone_sort_by',
            ['name', 'type'],
            SessionKeys::SEARCH_ZONE_SORT_BY
        );

        $this->assertSame('type', $sortBy);
        $this->assertSame('DESC', $direction);
        $this->assertSame('type', $_SESSION[SessionKeys::SEARCH_ZONE_SORT_BY]);
        $this->assertSame('DESC', $_SESSION[SessionKeys::SEARCH_ZONE_SORT_BY . '_direction']);
        // Search bucket must not leak into the list-zones bucket - that isolation
        // is what prevents the historical "ORDER BY domains.owner" crash.
        $this->assertFalse(isset($_SESSION[SessionKeys::LIST_ZONE_SORT_BY]));
    }

    #[Test]
    public function getZoneSortOrderReadsSessionFromCustomKey(): void
    {
        $_SESSION[SessionKeys::SEARCH_RECORD_SORT_BY] = 'prio';
        $_SESSION[SessionKeys::SEARCH_RECORD_SORT_BY . '_direction'] = 'DESC';
        // Stale list-zones value must be ignored when reading the search bucket.
        $_SESSION[SessionKeys::LIST_ZONE_SORT_BY] = 'type';

        [$sortBy, $direction] = $this->service->getZoneSortOrder(
            'record_sort_by',
            ['name', 'type', 'prio'],
            SessionKeys::SEARCH_RECORD_SORT_BY
        );

        $this->assertSame('prio', $sortBy);
        $this->assertSame('DESC', $direction);
    }

    #[Test]
    public function getZoneSortOrderFallsBackToDefaultWhenSessionValueDisallowed(): void
    {
        // Stored sort column is no longer in allowedValues (e.g. column hidden).
        $_SESSION[SessionKeys::LIST_ZONE_SORT_BY] = 'count_records';

        [$sortBy] = $this->service->getZoneSortOrder(
            'zone_sort_by',
            ['name', 'type']
        );

        $this->assertSame('name', $sortBy);
    }

    #[Test]
    public function getZoneSortOrderRespectsCustomDefaultSortBy(): void
    {
        [$sortBy] = $this->service->getZoneSortOrder(
            'zone_sort_by',
            ['type', 'name'],
            SessionKeys::LIST_ZONE_SORT_BY,
            'type'
        );

        $this->assertSame('type', $sortBy);
    }

    #[Test]
    public function getReverseZoneTypeFilterPersistsGetParamToSession(): void
    {
        $_GET['reverse_type'] = 'ipv4';

        $filter = $this->service->getReverseZoneTypeFilter();

        $this->assertSame('ipv4', $filter);
        $this->assertSame('ipv4', $_SESSION[SessionKeys::REVERSE_ZONE_TYPE]);
    }

    #[Test]
    public function getReverseZoneTypeFilterReadsFromSessionWhenNoRequestParam(): void
    {
        $_SESSION[SessionKeys::REVERSE_ZONE_TYPE] = 'ipv6';

        $this->assertSame('ipv6', $this->service->getReverseZoneTypeFilter());
    }

    #[Test]
    public function getReverseZoneTypeFilterReturnsAllByDefault(): void
    {
        $this->assertSame('all', $this->service->getReverseZoneTypeFilter());
    }
}
