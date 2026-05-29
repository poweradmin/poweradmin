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

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Exercises the SQLite 4.5.0 zones-table rebuild migration end-to-end against
// an in-memory database seeded to mimic a 4.4.x install (NOT NULL, no default).
class SqliteZonesMigrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE zones (
            id integer PRIMARY KEY,
            domain_id integer NULL DEFAULT NULL,
            owner integer NULL DEFAULT NULL,
            comment VARCHAR(1024),
            zone_templ_id integer NOT NULL,
            zone_name VARCHAR(255) DEFAULT NULL,
            zone_type VARCHAR(8) DEFAULT NULL,
            zone_master VARCHAR(255) DEFAULT NULL
        )");
        $this->db->exec("CREATE INDEX idx_zones_domain_id ON zones(domain_id)");
        $this->db->exec("CREATE INDEX idx_zones_owner ON zones(owner)");
        $this->db->exec("CREATE INDEX idx_zones_zone_templ_id ON zones(zone_templ_id)");
        $this->db->exec("CREATE UNIQUE INDEX idx_zones_zone_name ON zones(zone_name)");

        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type, zone_master)
            VALUES (1, 100, 5, 'first', 0, 'example.com', 'MASTER', NULL),
                   (2, 101, 6, NULL, 7, 'example.org', 'SLAVE', '192.0.2.1')");

        // Migration also writes perm_items; satisfy that statement's table dep.
        $this->db->exec("CREATE TABLE perm_items (
            id integer PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            descr VARCHAR(1024) NOT NULL
        )");

        // Migration ALTERs login_attempts to add the attempt_type column.
        $this->db->exec("CREATE TABLE login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            ip_address VARCHAR(45) NOT NULL,
            timestamp INTEGER NOT NULL,
            successful BOOLEAN NOT NULL
        )");
    }

    #[Test]
    public function migrationPreservesRowsAndAddsDefaultZero(): void
    {
        $this->runMigration();

        $rows = $this->db->query("SELECT id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type, zone_master FROM zones ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame(['id' => 1, 'domain_id' => 100, 'owner' => 5, 'comment' => 'first', 'zone_templ_id' => 0, 'zone_name' => 'example.com', 'zone_type' => 'MASTER', 'zone_master' => null], $rows[0]);
        $this->assertSame(['id' => 2, 'domain_id' => 101, 'owner' => 6, 'comment' => null, 'zone_templ_id' => 7, 'zone_name' => 'example.org', 'zone_type' => 'SLAVE', 'zone_master' => '192.0.2.1'], $rows[1]);
    }

    #[Test]
    public function migrationLetsInsertOmitZoneTemplId(): void
    {
        $this->runMigration();

        $stmt = $this->db->prepare("INSERT INTO zones (domain_id, owner, comment, zone_name, zone_type) VALUES (200, 9, '', 'example.net', 'NATIVE')");
        $stmt->execute();

        $row = $this->db->query("SELECT zone_templ_id FROM zones WHERE zone_name = 'example.net'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, $row['zone_templ_id']);
    }

    #[Test]
    public function migrationPreservesNotNullConstraint(): void
    {
        $this->runMigration();

        $this->expectException(\PDOException::class);
        $this->db->exec("INSERT INTO zones (domain_id, owner, comment, zone_templ_id, zone_name) VALUES (300, 9, '', NULL, 'null.example')");
    }

    #[Test]
    public function migrationPreservesIndexes(): void
    {
        $this->runMigration();

        $indexNames = $this->db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='zones' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(
            ['idx_zones_domain_id', 'idx_zones_owner', 'idx_zones_zone_name', 'idx_zones_zone_templ_id'],
            $indexNames
        );
    }

    private function runMigration(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 4) . '/sql/poweradmin-sqlite-update-to-4.5.0.sql');
        $this->assertIsString($sql);
        $this->db->exec($sql);
    }
}
