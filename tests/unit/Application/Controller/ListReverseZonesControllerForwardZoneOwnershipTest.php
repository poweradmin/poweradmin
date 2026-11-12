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
use Poweradmin\Application\Controller\ListReverseZonesController;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The forward zone column on the reverse zone list must not confirm that a zone
 * the viewer has no access to exists.
 */
class ListReverseZonesControllerForwardZoneOwnershipTest extends TestCase
{
    private ListReverseZonesController $controller;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER)");
        $db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES
            (1, 100, 'mine.example.com', 7),
            (2, 200, 'theirs.example.com', 8),
            (3, 300, 'shared.example.com', 8)");
        $db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (300, 5)");
        $db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (7, 5)");

        $this->controller = (new ReflectionClass(ListReverseZonesController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($this->controller, 'db'))->setValue($this->controller, $db);
    }

    public function testKeepsOnlyZonesTheViewerOwnsDirectlyOrByGroup(): void
    {
        $associations = [
            50 => [
                ['id' => 100, 'name' => 'mine.example.com', 'ptr_records' => 1],
                ['id' => 200, 'name' => 'theirs.example.com', 'ptr_records' => 2],
                ['id' => 300, 'name' => 'shared.example.com', 'ptr_records' => 1],
            ],
            51 => [['id' => 200, 'name' => 'theirs.example.com', 'ptr_records' => 1]],
        ];

        $method = new ReflectionMethod($this->controller, 'keepOwnedForwardZones');
        $result = $method->invoke($this->controller, $associations, 7);

        $this->assertSame([100, 300], array_column($result[50], 'id'));
        $this->assertSame([], $result[51]);
    }
}
