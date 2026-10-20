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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;

/**
 * removeAllForDomain() drops every group owner of one zone and nothing else.
 */
#[CoversClass(DbZoneGroupRepository::class)]
class DbZoneGroupRepositoryRemoveAllForDomainTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER, created_at TEXT DEFAULT '')");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (77, 3), (77, 4), (78, 3)");
    }

    public function testOnlyTheGivenZoneLosesItsGroups(): void
    {
        (new DbZoneGroupRepository($this->db))->removeAllForDomain(77);

        $this->assertSame(
            [['domain_id' => 78, 'group_id' => 3]],
            $this->db->query('SELECT domain_id, group_id FROM zones_groups')->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}
