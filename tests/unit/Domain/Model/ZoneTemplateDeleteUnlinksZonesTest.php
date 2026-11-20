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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * The web template delete unlinks the template's zones, so a later template that reuses
 * the id is not linked to them.
 */
class ZoneTemplateDeleteUnlinksZonesTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testDeletingATemplateUnlinksItsZones(): void
    {
        $db = new PDO('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)",
                "CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL)",
                "CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER NOT NULL, perm_id INTEGER NOT NULL)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, perm_templ INTEGER NOT NULL)",
                "CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)",
                "CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER)",
                "CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)",
                "INSERT INTO perm_items (id, name) VALUES (1, 'zone_templ_edit')",
                "INSERT INTO perm_templ (id, name) VALUES (1, 'templates')",
                "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1)",
                "INSERT INTO users (id, perm_templ) VALUES (7, 1)",
                "INSERT INTO zone_templ (id, name, owner) VALUES (5, 'gone', 7), (6, 'stays', 7)",
                "INSERT INTO zone_templ_records (zone_templ_id) VALUES (5), (6)",
                "INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (10, 1, 5), (11, 2, 6)",
                "INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (10, 7, 5), (11, 7, 6)",
            ] as $sql
        ) {
            $db->exec($sql);
        }
        $_SESSION['userid'] = 7;

        $this->assertTrue((new ZoneTemplate($db, $this->createMock(ConfigurationInterface::class)))->deleteZoneTempl(5));

        $this->assertSame([0, 6], array_map('intval', $db->query("SELECT zone_templ_id FROM zones ORDER BY domain_id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([6], array_map('intval', $db->query("SELECT id FROM zone_templ")->fetchAll(PDO::FETCH_COLUMN)));
    }
}
