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
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use TestHelpers\FakeConfiguration;

/**
 * The template repository keys zones by canonical id in three places: the template name
 * of a zone, the linked-zone listing and the owner filter over zones_groups. Only the API
 * backend may fall back to zones.id; in SQL mode the two id spaces overlap.
 */
class DbZoneTemplateRepositoryCanonicalZoneTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER)");

        $this->db->exec("INSERT INTO zone_templ (id, name, owner) VALUES (1, 'Stranded template', 1), (2, 'Collision template', 1)");
        // Row 55 is stranded at domain_id 0 (API mode only); row 7 is a migrated zone.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner, zone_templ_id) VALUES
            (55, 0, 'stranded.example.com', 1, 1),
            (7, 201, 'migrated.example.com', 2, 1)");
        // Group 3 (user 4) owns canonical zones 55 and 201.
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (55, 3), (201, 3)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (4, 3)");
    }

    /**
     * SQL mode: zones.id is an unrelated id space, so add a zone whose domain_id collides
     * with the stranded row's primary key and the domains rows the SQL source joins.
     */
    private function seedSqlModeCollision(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner, zone_templ_id) VALUES (9, 55, 'collision.example.com', 2, 2)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (55, 'collision.example.com', 'NATIVE'), (201, 'migrated.example.com', 'NATIVE')");
    }

    private function repository(bool $zonesTableIsCanonical): DbZoneTemplateRepository
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('allocatesZoneIdsLocally')->willReturn($zonesTableIsCanonical);

        return new DbZoneTemplateRepository($this->db, new FakeConfiguration(), $backend);
    }

    public function testApiBackendResolvesTheTemplateOfAStrandedZone(): void
    {
        $repository = $this->repository(true);

        $this->assertSame('Stranded template', $repository->getTemplateNameForZone(55));
        $this->assertSame('Stranded template', $repository->getTemplateNameForZone(201));
    }

    public function testSqlBackendResolvesTheTemplateByDomainIdOnly(): void
    {
        $this->seedSqlModeCollision();
        $repository = $this->repository(false);

        $this->assertSame('Collision template', $repository->getTemplateNameForZone(55));
        $this->assertSame('Stranded template', $repository->getTemplateNameForZone(201));
    }

    public function testApiBackendListsGroupOwnedLinkedZonesThroughTheRowId(): void
    {
        $ids = $this->repository(true)->listLinkedZoneIds(1, 4);
        sort($ids);

        $this->assertSame([55, 201], $ids);
    }

    public function testSqlBackendListsGroupOwnedLinkedZonesByDomainIdOnly(): void
    {
        $this->seedSqlModeCollision();
        $repository = $this->repository(false);

        // Zone 55 (stranded) is linked to template 1 but has no domain_id, so it is not
        // the group's zone 55; that is the collision zone linked to template 2.
        $this->assertSame([201], $repository->listLinkedZoneIds(1, 4));
        $this->assertSame([55], $repository->listLinkedZoneIds(2, 4));
    }
}
