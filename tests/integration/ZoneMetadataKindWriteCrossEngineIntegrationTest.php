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

namespace Poweradmin\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbZoneMetadataStore;
use TestHelpers\FakeConfiguration;

/**
 * A single-kind metadata write changes only that kind's rows, on every engine, so a
 * kind another request wrote after the snapshot survives (#1832).
 * MySQL and PostgreSQL run when the devcontainer is up.
 */
class ZoneMetadataKindWriteCrossEngineIntegrationTest extends TestCase
{
    private ?PDO $db = null;

    private static function scratchName(): string
    {
        return 'poweradmin_it_metadata_kind_' . getmypid();
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function store(string $engine): DbZoneMetadataStore
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            if ($engine === 'mysql') {
                $db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options);
                $db->exec('CREATE DATABASE IF NOT EXISTS ' . self::scratchName());
                $db->exec('USE ' . self::scratchName());
            } elseif ($engine === 'pgsql') {
                $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
                $db->exec('DROP SCHEMA IF EXISTS ' . self::scratchName() . ' CASCADE');
                $db->exec('CREATE SCHEMA ' . self::scratchName());
                $db->exec('SET search_path TO ' . self::scratchName());
            } else {
                $db = new PDO('sqlite::memory:', null, null, $options);
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        $id = match ($engine) {
            'mysql' => 'id INT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'id SERIAL PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY',
        };
        $db->exec('DROP TABLE IF EXISTS domainmetadata');
        $db->exec("CREATE TABLE domainmetadata ($id, domain_id INT NOT NULL, kind VARCHAR(32), content TEXT)");
        $db->exec("INSERT INTO domainmetadata (domain_id, kind, content) VALUES
            (1, 'ALLOW-AXFR-FROM', '192.0.2.1'), (1, 'x-note', 'lower'), (2, 'X-NOTE', 'other zone')");
        $this->db = $db;

        return new DbZoneMetadataStore($db, new FakeConfiguration(['database' => ['type' => $engine, 'pdns_db_name' => '']]));
    }

    /** @return list<string> */
    private function rowsOf(int $zoneId): array
    {
        $stmt = $this->db->query("SELECT kind, content FROM domainmetadata WHERE domain_id = $zoneId ORDER BY id");

        return array_map(fn(array $row): string => $row['kind'] . '=' . $row['content'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::scratchName());
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::scratchName() . ' CASCADE');
        } catch (PDOException) {
        }
    }

    #[DataProvider('engines')]
    public function testAKindWrittenAfterTheSnapshotSurvives(string $engine): void
    {
        $store = $this->store($engine);
        $before = $store->load(1, 'example.com');
        $this->db->exec("INSERT INTO domainmetadata (domain_id, kind, content) VALUES (1, 'ALSO-NOTIFY', '192.0.2.2')");

        $this->assertTrue($store->replaceKind(1, 'example.com', 'X-NOTE', ['new'], $before));

        $this->assertSame(['ALLOW-AXFR-FROM=192.0.2.1', 'ALSO-NOTIFY=192.0.2.2', 'X-NOTE=new'], $this->rowsOf(1));
        $this->assertSame(['X-NOTE=other zone'], $this->rowsOf(2));
    }

    #[DataProvider('engines')]
    public function testDeletingAKindLeavesTheOthers(string $engine): void
    {
        $store = $this->store($engine);

        $this->assertTrue($store->replaceKind(1, 'example.com', 'X-NOTE', [], []));

        $this->assertSame(['ALLOW-AXFR-FROM=192.0.2.1'], $this->rowsOf(1));
    }
}
