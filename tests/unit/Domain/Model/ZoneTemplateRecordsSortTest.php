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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneTemplate;

class ZoneTemplateRecordsSortTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER)");
        $insert = $this->db->prepare("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (1, :name, 'MX', 'mail.example.com', 60, :prio)");
        foreach (['aaa' => 30, 'bbb' => 10, 'ccc' => 20] as $name => $prio) {
            $insert->execute([':name' => $name, ':prio' => $prio]);
        }
    }

    public function testSortsByPriority(): void
    {
        $names = array_column(ZoneTemplate::getZoneTemplRecords($this->db, 1, 0, 9999, 'prio'), 'name');
        $this->assertSame(['bbb', 'ccc', 'aaa'], $names);
    }

    public function testFallsBackToNameForAnUnknownColumn(): void
    {
        $names = array_column(ZoneTemplate::getZoneTemplRecords($this->db, 1, 0, 9999, 'priority'), 'name');
        $this->assertSame(['aaa', 'bbb', 'ccc'], $names);
    }
}
