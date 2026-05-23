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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\ListForwardZonesController;
use ReflectionClass;

#[CoversClass(ListForwardZonesController::class)]
class ZoneListSortFallbackTest extends TestCase
{
    private ListForwardZonesController $controller;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(ListForwardZonesController::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();

        unset($_GET['zone_sort_by'], $_GET['zone_sort_by_direction']);
        unset($_POST['zone_sort_by'], $_POST['zone_sort_by_direction']);
        unset($_SESSION['list_zone_sort_by'], $_SESSION['list_zone_sort_by_direction']);
    }

    #[Test]
    public function testSortByCountRecordsHonouredWhenAllowed(): void
    {
        $_GET['zone_sort_by'] = 'count_records';

        [$sortBy, $direction] = $this->controller->getZoneSortOrder(
            'zone_sort_by',
            ['name', 'type', 'count_records']
        );

        $this->assertSame('count_records', $sortBy);
        $this->assertSame('ASC', $direction);
    }

    #[Test]
    public function testSortByCountRecordsFallsBackToNameWhenColumnHidden(): void
    {
        // Stale sort preference: the user previously sorted by count_records,
        // but the column is now hidden so allowedSort no longer includes it.
        $_GET['zone_sort_by'] = 'count_records';

        [$sortBy, $direction] = $this->controller->getZoneSortOrder(
            'zone_sort_by',
            ['name', 'type']
        );

        $this->assertSame('name', $sortBy);
        $this->assertSame('ASC', $direction);
    }

    #[Test]
    public function testStaleSessionSortFallsBackToNameWhenColumnHidden(): void
    {
        $_SESSION['list_zone_sort_by'] = 'count_records';

        [$sortBy] = $this->controller->getZoneSortOrder(
            'zone_sort_by',
            ['name', 'type']
        );

        $this->assertSame('name', $sortBy);
    }
}
