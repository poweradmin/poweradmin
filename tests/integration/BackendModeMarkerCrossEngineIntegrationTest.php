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
use Poweradmin\Infrastructure\Database\BackendModeMarker;

/**
 * The API-mode marker and the one-time classification on every engine: the insert-if-missing
 * inside a transaction (which must not abort a PostgreSQL transaction), the LEFT JOIN with
 * LOWER() and NULL domain ids, and on MySQL a domains table in a separate database.
 * MySQL and PostgreSQL run when the devcontainer is up.
 */
class BackendModeMarkerCrossEngineIntegrationTest extends TestCase
{
    /** Per-process names, so parallel suite runs do not drop each other's scratch data. */
    private static function pgsqlSchema(): string
    {
        return 'poweradmin_it_marker_' . getmypid();
    }

    private static function mysqlDb(): string
    {
        return 'poweradmin_it_marker_' . getmypid();
    }

    private static function mysqlPdnsDb(): string
    {
        return 'poweradmin_it_marker_pdns_' . getmypid();
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    /** @return array{PDO, string} the connection and its domains table */
    private function connect(string $engine): array
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        $domains = 'domains';
        try {
            if ($engine === 'mysql') {
                $db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options);
                foreach ([self::mysqlDb(), self::mysqlPdnsDb()] as $name) {
                    $db->exec("DROP DATABASE IF EXISTS $name");
                    $db->exec("CREATE DATABASE $name");
                }
                $db->exec('USE ' . self::mysqlDb());
                $domains = self::mysqlPdnsDb() . '.domains';
            } elseif ($engine === 'pgsql') {
                $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
                $db->exec('DROP SCHEMA IF EXISTS ' . self::pgsqlSchema() . ' CASCADE');
                $db->exec('CREATE SCHEMA ' . self::pgsqlSchema());
                $db->exec('SET search_path TO ' . self::pgsqlSchema());
            } else {
                $db = new PDO('sqlite::memory:', null, null, $options);
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        $db->exec("CREATE TABLE $domains (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL)");
        $db->exec("CREATE TABLE zones (id INT PRIMARY KEY, domain_id INT NULL, owner INT NULL, zone_name VARCHAR(255) NULL)");
        $db->exec("CREATE TABLE app_settings (setting_key VARCHAR(128) PRIMARY KEY, setting_value TEXT NOT NULL, value_type VARCHAR(16) NOT NULL DEFAULT 'string')");
        $db->exec("INSERT INTO $domains (id, name) VALUES (10, 'a.example'), (11, 'b.example')");

        return [$db, $domains];
    }

    protected function tearDown(): void
    {
        try {
            $db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser');
            $db->exec('DROP DATABASE IF EXISTS ' . self::mysqlDb());
            $db->exec('DROP DATABASE IF EXISTS ' . self::mysqlPdnsDb());
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::pgsqlSchema() . ' CASCADE');
        } catch (PDOException) {
        }
    }

    #[DataProvider('engines')]
    public function testSqlModeDataIsLetThroughAndRemembered(string $engine): void
    {
        [$db, $domains] = $this->connect($engine);
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 10, 1, 'A.Example'), (2, 10, 2, NULL), (3, 99, 1, 'gone.example')");

        $this->assertNull(BackendModeMarker::sqlModeRefusal($db, $domains));
        $this->assertSame(BackendModeMarker::SQL, $db->query("SELECT setting_value FROM app_settings")->fetchColumn());
    }

    #[DataProvider('engines')]
    public function testApiModeRowsAreRefused(string $engine): void
    {
        [$db, $domains] = $this->connect($engine);
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (11, 11, 1, 'a.example')");

        $this->assertSame(BackendModeMarker::SQL_MODE_REFUSAL, BackendModeMarker::sqlModeRefusal($db, $domains));
    }

    #[DataProvider('engines')]
    public function testMarkingTwiceInsideATransactionKeepsItUsable(string $engine): void
    {
        [$db, $domains] = $this->connect($engine);
        $db->beginTransaction();
        BackendModeMarker::markApi($db);
        BackendModeMarker::markApi($db);
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 1, NULL, 'c.example')");
        $db->commit();

        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM zones")->fetchColumn());
        $this->assertSame(BackendModeMarker::SQL_MODE_REFUSAL, BackendModeMarker::sqlModeRefusal($db, $domains));
    }
}
