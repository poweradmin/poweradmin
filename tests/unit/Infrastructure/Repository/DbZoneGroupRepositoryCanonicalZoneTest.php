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
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;

/**
 * findByGroupId() names the group's zones through the zones table. Only the API backend
 * may fall back to zones.id for a row without a domain_id; in SQL mode the two id spaces
 * overlap and that fallback would name an unrelated zone.
 */
class DbZoneGroupRepositoryCanonicalZoneTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, zone_type TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER, created_at TEXT DEFAULT '')");

        // A stranded zone (domain_id 0) and a migrated one (id 7 / domain_id 201).
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, owner) VALUES
            (55, 0, 'stranded.example.com', 'NATIVE', 1),
            (7, 201, 'migrated.example.com', 'NATIVE', 1)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (55, 3), (201, 3)");
    }

    private function repository(bool $isApiBackend): DbZoneGroupRepository
    {
        return new DbZoneGroupRepository($this->db, null, $isApiBackend);
    }

    /**
     * @return array<int, string|null> zone name keyed by zones_groups.domain_id
     */
    private function namesByDomainId(DbZoneGroupRepository $repository): array
    {
        $names = [];
        foreach ($repository->findByGroupId(3) as $row) {
            $names[$row->getDomainId()] = $row->getName();
        }
        ksort($names);

        return $names;
    }

    public function testApiBackendNamesAStrandedZoneThroughItsRowId(): void
    {
        $this->assertSame(
            [55 => 'stranded.example.com', 201 => 'migrated.example.com'],
            $this->namesByDomainId($this->repository(true))
        );
    }

    public function testSqlBackendMatchesDomainIdOnly(): void
    {
        // In SQL mode zones.id is an unrelated id space, so the group entry keyed 55 must
        // name the zone whose domain_id is 55, never the row whose primary key is 55.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, owner) VALUES (9, 55, 'collision.example.com', 'NATIVE', 1)");

        $this->assertSame(
            [55 => 'collision.example.com', 201 => 'migrated.example.com'],
            $this->namesByDomainId($this->repository(false))
        );
    }
}
