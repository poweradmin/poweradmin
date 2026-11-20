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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;

/**
 * Deleting a template unlinks its zones, so a later template that reuses the id is not
 * linked to them.
 */
class DbZoneTemplateRepositoryDeleteTest extends TestCase
{
    public function testDeletingATemplateUnlinksItsZones(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER)",
                "CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)",
                "INSERT INTO zone_templ (id, name, owner) VALUES (5, 'gone', 1), (6, 'stays', 1)",
                "INSERT INTO zone_templ_records (zone_templ_id) VALUES (5), (6)",
                "INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (10, 1, 5), (11, 2, 6)",
                "INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (10, 1, 5), (11, 1, 6)",
            ] as $sql
        ) {
            $db->exec($sql);
        }

        $this->assertTrue((new DbZoneTemplateRepository($db, $this->createMock(ConfigurationManager::class)))->deleteZoneTemplate(5));

        $this->assertSame([0, 6], array_map('intval', $db->query("SELECT zone_templ_id FROM zones ORDER BY domain_id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([6], array_map('intval', $db->query("SELECT id FROM zone_templ")->fetchAll(PDO::FETCH_COLUMN)));
    }
}
