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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * Deleting a zone removes every dependent row, including the record comments
 * and their Poweradmin-side links that no foreign key cascades to.
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryDeleteZoneTest extends TestCase
{
    private PDO $db;
    private DbZoneRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)",
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)",
                "CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, comment TEXT)",
                "CREATE TABLE record_comment_links (id INTEGER PRIMARY KEY, record_id INTEGER, comment_id INTEGER)",
                "CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ_api (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)",
                "CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)",
                "CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, flags INTEGER, content TEXT)",
                "INSERT INTO domains VALUES (1, 'example.com', 'MASTER'), (2, 'other.com', 'MASTER')",
                "INSERT INTO records VALUES (10, 1, 'www.example.com', 'A', '192.0.2.1'), (20, 2, 'www.other.com', 'A', '192.0.2.2')",
                "INSERT INTO comments VALUES (100, 1, 'www.example.com', 'A', 'web'), (200, 2, 'www.other.com', 'A', 'other web')",
                "INSERT INTO record_comment_links VALUES (1, 10, 100), (2, 20, 200)",
                "INSERT INTO zones VALUES (1, 1, 1), (2, 2, 1)",
                "INSERT INTO domainmetadata VALUES (1, 1, 'SOA-EDIT-API', 'DEFAULT')",
                "INSERT INTO cryptokeys VALUES (1, 1, 257, 'key')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );
        $this->repository = new DbZoneRepository($this->db, $config);
    }

    public function testDeleteZoneRemovesCommentsAndTheirLinks(): void
    {
        $this->assertTrue($this->repository->deleteZone(1));

        $this->assertSame(0, $this->rows('comments WHERE domain_id = 1'));
        $this->assertSame(0, $this->rows('record_comment_links WHERE comment_id = 100'));
        $this->assertSame(0, $this->rows('records WHERE domain_id = 1'));
        $this->assertSame(0, $this->rows('domainmetadata WHERE domain_id = 1'));
        $this->assertSame(0, $this->rows('cryptokeys WHERE domain_id = 1'));
        $this->assertSame(0, $this->rows('domains WHERE id = 1'));
    }

    public function testDeleteZoneLeavesOtherZonesAlone(): void
    {
        $this->repository->deleteZone(1);

        $this->assertSame(1, $this->rows('comments WHERE domain_id = 2'));
        $this->assertSame(1, $this->rows('record_comment_links WHERE comment_id = 200'));
        $this->assertSame(1, $this->rows('records WHERE domain_id = 2'));
    }

    private function rows(string $fromWhere): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $fromWhere")->fetchColumn();
    }
}
