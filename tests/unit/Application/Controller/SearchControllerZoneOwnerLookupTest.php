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

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\SearchController;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * fetchDirectZoneOwners() drives the search page's owner column and its edit/delete
 * controls. It matches on the canonical id expression, which carries no column affinity,
 * so ids bound as strings matched nothing on SQLite and every directly owned zone looked
 * unowned.
 */
class SearchControllerZoneOwnerLookupTest extends TestCase
{
    private PDO $db;
    private SearchController $controller;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER)");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES
            (1, 100, 'migrated.example.com', 10),
            (55, 0, 'stranded.example.com', 20),
            (56, NULL, 'nulled.example.com', 30)");

        $this->controller = (new ReflectionClass(SearchController::class))->newInstanceWithoutConstructor();
        $db = new ReflectionProperty($this->controller, 'db');
        $db->setAccessible(true);
        $db->setValue($this->controller, $this->db);
    }

    /**
     * @param int[] $zoneIds
     * @return array<int, int[]>
     */
    private function fetchDirectZoneOwners(array $zoneIds): array
    {
        $method = new ReflectionMethod($this->controller, 'fetchDirectZoneOwners');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $zoneIds);
    }

    public function testResolvesOwnersForEveryDomainIdShape(): void
    {
        $map = $this->fetchDirectZoneOwners([100, 55, 56]);

        $this->assertSame([10], $map[100] ?? [], 'migrated zone lost its owner');
        $this->assertSame([20], $map[55] ?? [], 'stranded zone lost its owner');
        $this->assertSame([30], $map[56] ?? [], 'null domain_id zone lost its owner');
    }

    public function testDoesNotResolveAMigratedZoneByItsRowId(): void
    {
        $map = $this->fetchDirectZoneOwners([1]);

        $this->assertSame([], $map, 'the canonical fallback fired on a populated domain_id');
    }

    public function testReturnsAnEmptyMapForNoIds(): void
    {
        $this->assertSame([], $this->fetchDirectZoneOwners([]));
    }

    public function testAggregatesSeveralOwnersOfOneZone(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (57, 55, NULL, 40)");

        $owners = $this->fetchDirectZoneOwners([55])[55];
        sort($owners);

        $this->assertSame([20, 40], $owners);
    }
}
