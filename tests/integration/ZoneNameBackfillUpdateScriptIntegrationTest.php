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

/**
 * The zone name backfill at the end of the 4.6.0 update scripts (#1648) on every engine:
 * unnamed zones get named, zones that already have a named row or whose name another row
 * holds are left alone, and a re-run changes nothing. MySQL and PostgreSQL run when the
 * devcontainer is up.
 */
class ZoneNameBackfillUpdateScriptIntegrationTest extends TestCase
{
    private const MARKER = '-- Name zones left unnamed by older versions (#1648)';

    private const ZONE_KEYED = [
        'log_zones' => 'zone_id', 'log_changesets' => 'zone_id', 'log_record_changes' => 'zone_id',
        'zone_change_requests' => 'zone_id', 'records_zone_templ' => 'domain_id', 'records_zone_templ_api' => 'domain_id',
    ];

    private static function scratch(): string
    {
        return 'poweradmin_it_zname_' . getmypid();
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            if ($engine === 'mysql') {
                $db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options);
                $db->exec('CREATE DATABASE IF NOT EXISTS ' . self::scratch());
                $db->exec('USE ' . self::scratch());
            } elseif ($engine === 'pgsql') {
                $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
                $db->exec('DROP SCHEMA IF EXISTS ' . self::scratch() . ' CASCADE');
                $db->exec('CREATE SCHEMA ' . self::scratch());
                $db->exec('SET search_path TO ' . self::scratch());
            } else {
                $db = new PDO('sqlite::memory:', null, null, $options);
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        return $db;
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::scratch());
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::scratch() . ' CASCADE');
        } catch (PDOException) {
        }
    }

    /** Tables as the 4.5.0 structure files declare them, trimmed to the columns the backfill reads */
    private function createTables(PDO $db, string $engine, bool $withDomains = true, string $domainsCollation = '', string $threeName = 'three.example'): void
    {
        $id = match ($engine) {
            'mysql' => 'id INT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'id SERIAL PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY',
        };
        $db->exec("CREATE TABLE zones ($id, domain_id INT NULL DEFAULT NULL, owner INT NULL DEFAULT NULL,
            comment VARCHAR(1024), zone_templ_id INT NOT NULL DEFAULT 0, zone_name VARCHAR(255) DEFAULT NULL,
            zone_type VARCHAR(8) DEFAULT NULL, zone_master VARCHAR(255) DEFAULT NULL)" . ($engine === 'mysql' ? ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci' : ''));
        $db->exec('CREATE UNIQUE INDEX idx_zones_zone_name ON zones (zone_name)');
        $db->exec('CREATE TABLE zones_groups (id INT PRIMARY KEY, domain_id INT NOT NULL, group_id INT NOT NULL)');
        $db->exec('CREATE TABLE api_key_zones (id INT PRIMARY KEY, api_key_id INT NOT NULL, zone_id INT NOT NULL)');
        $db->exec('CREATE TABLE app_settings (setting_key VARCHAR(255) NOT NULL PRIMARY KEY, setting_value VARCHAR(255) NOT NULL)');
        foreach (self::ZONE_KEYED as $table => $column) {
            $db->exec("CREATE TABLE $table (id INT PRIMARY KEY, $column INT NOT NULL)");
        }

        if ($withDomains) {
            $db->exec('CREATE TABLE domains (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL, type VARCHAR(8), master VARCHAR(128))'
                . ($domainsCollation !== '' ? " DEFAULT CHARSET=utf8mb4 COLLATE=$domainsCollation" : ''));
            $db->exec("INSERT INTO domains (id, name, type) VALUES
                (1, 'one.example', 'NATIVE'), (2, 'two.example', 'NATIVE'), (3, '$threeName', 'NATIVE'),
                (4, 'four.example', 'NATIVE'), (5, 'five.example', 'NATIVE'), (6, 'six.example', 'NATIVE'),
                (7, 'seven.example', 'NATIVE'), (8, 'eight.example', 'NATIVE')");
        }

        // zones.id is explicit so the rows' order is fixed on every engine
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES
            (1, 1, 10, NULL),
            (2, 2, 10, NULL), (3, 2, 11, 'two.example'),
            (4, 3, 10, NULL), (5, 99, 10, 'three.example'),
            (6, 4, 10, NULL), (7, 4, 11, NULL),
            (8, 7, 10, 'old.example'),
            (9, 8, 10, NULL),
            (10, NULL, 10, NULL)");
        if ($engine === 'pgsql') {
            $db->exec("SELECT setval(pg_get_serial_sequence('zones', 'id'), 10)");
        }
        $db->exec('INSERT INTO zones_groups (id, domain_id, group_id) VALUES (1, 5, 1), (2, 8, 1)');
        $db->exec('INSERT INTO api_key_zones (id, api_key_id, zone_id) VALUES (1, 1, 5)');
    }

    private function backfillSql(string $engine): string
    {
        $root = dirname(__DIR__, 2) . '/sql/';
        if ($engine === 'sqlite') {
            $sql = file_get_contents($root . 'poweradmin-sqlite-update-to-4.6.0-backfill.sql');
        } else {
            $sql = (string)file_get_contents($root . "poweradmin-$engine-update-to-4.6.0.sql");
            $start = strpos($sql, self::MARKER);
            $this->assertNotFalse($start, "$engine 4.6.0 update has no zone name backfill");
            $sql = substr($sql, $start);
        }
        $this->assertIsString($sql);

        return $sql;
    }

    /** @return array<int, array{int|null, string|null}> zones.id => [domain_id, zone_name] */
    private function zones(PDO $db): array
    {
        $rows = [];
        foreach ($db->query('SELECT id, domain_id, zone_name FROM zones ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['id']] = [$row['domain_id'] === null ? null : (int)$row['domain_id'], $row['zone_name']];
        }

        return $rows;
    }

    #[DataProvider('engines')]
    public function testUnnamedZonesAreNamedAndEverythingElseIsLeftAlone(string $engine): void
    {
        $db = $this->connect($engine);
        $this->createTables($db, $engine);

        $db->exec($this->backfillSql($engine));
        $zones = $this->zones($db);

        $this->assertSame('one.example', $zones[1][1], 'an unnamed zone is named');
        $this->assertNull($zones[2][1], 'a zone with a named row keeps its lowest row unnamed');
        $this->assertSame('two.example', $zones[3][1]);
        $this->assertNull($zones[4][1], 'a name held by another domain_id is skipped');
        $this->assertSame('three.example', $zones[5][1]);
        $this->assertSame('four.example', $zones[6][1], 'only the lowest row of a zone is named');
        $this->assertNull($zones[7][1]);
        $this->assertSame('old.example', $zones[8][1], 'a stale name is left to the consistency fix');
        $this->assertSame('eight.example', $zones[9][1]);

        $added = array_filter($zones, static fn(array $z): bool => $z[1] === 'five.example');
        $this->assertCount(1, $added, 'a zone known only by a grant gets an ownerless named row');
        $row = $db->query("SELECT domain_id, owner, zone_templ_id FROM zones WHERE zone_name = 'five.example'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([5, null, 0], [(int)$row['domain_id'], $row['owner'] === null ? null : (int)$row['owner'], (int)$row['zone_templ_id']]);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM zones WHERE domain_id = 6")->fetchColumn(), 'a zone nobody uses gets no row');
        $this->assertNull($zones[10][1], 'a row without a domain is left alone');
        $this->assertCount(11, $zones, 'only the one grant-only row is added');
    }

    #[DataProvider('engines')]
    public function testAnUnusedRowTheApiSyncAddedIsReplacedByTheZonesOwnRow(string $engine): void
    {
        $db = $this->connect($engine);
        $this->createTables($db, $engine);
        // Rows the API sync added before 4.6.0, each keyed by its own id (#1855). 30 and 40
        // have an owner or a group grant, 50 has neither but no row of its domain to give back
        $db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_name) VALUES
            (20, 20, 0, '', 'one.example'), (30, 30, 12, '', 'eight.example'),
            (40, 40, 0, '', 'four.example'), (50, 50, 0, '', 'six.example')");
        $db->exec('INSERT INTO zones_groups (id, domain_id, group_id) VALUES (3, 40, 1)');
        foreach (self::ZONE_KEYED as $table => $column) {
            $db->exec("INSERT INTO $table (id, $column) VALUES (1, 20), (2, 30), (3, 1)");
        }
        if ($engine === 'pgsql') {
            $db->exec("SELECT setval(pg_get_serial_sequence('zones', 'id'), 50)");
        }

        $db->exec($this->backfillSql($engine));
        $zones = $this->zones($db);

        $this->assertArrayNotHasKey(20, $zones, 'an unused copy goes');
        $this->assertSame('one.example', $zones[1][1], 'and the zone row takes the name');
        $this->assertSame([30, 'eight.example'], $zones[30], 'a copy with an owner stays');
        $this->assertNull($zones[9][1]);
        $this->assertSame([40, 'four.example'], $zones[40], 'a copy with a group grant stays');
        $this->assertNull($zones[6][1]);
        $this->assertSame([50, 'six.example'], $zones[50], 'a copy of a zone with no rows here stays');
        $this->assertSame('three.example', $zones[5][1], 'an owned row keyed by another id stays');
        foreach (self::ZONE_KEYED as $table => $column) {
            $ids = array_map('intval', $db->query("SELECT $column FROM $table ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame([1, 30, 1], $ids, "$table rows of the removed copy move to the zone");
        }

        $db->exec($this->backfillSql($engine));
        $this->assertSame($zones, $this->zones($db), 'a re-run changes nothing');
    }

    #[DataProvider('engines')]
    public function testACopyIsKeptWhenOnlyAGrantOrAnUnsettledRowPointsAtItsDomain(string $engine): void
    {
        $db = $this->connect($engine);
        $this->createTables($db, $engine);
        // API mode with the PowerDNS tables alongside: 60 and 102 were synced in. Domain 5 is
        // reached only by a stale grant; domain 100's id is held by row 100, whose canonical id
        // never settled (domain_id NULL), and row 101 is that zone's extra owner
        $db->exec("INSERT INTO domains (id, name, type) VALUES (100, 'hundred.example', 'NATIVE')");
        $db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_name) VALUES
            (60, 60, 0, '', 'five.example'), (100, NULL, 5, '', 'other.example'),
            (101, 100, 6, NULL, NULL), (102, 102, 0, '', 'hundred.example')");
        if ($engine === 'pgsql') {
            $db->exec("SELECT setval(pg_get_serial_sequence('zones', 'id'), 102)");
        }

        $db->exec($this->backfillSql($engine));
        $zones = $this->zones($db);

        $this->assertSame([60, 'five.example'], $zones[60], 'a grant alone does not remove a copy');
        $this->assertSame([102, 'hundred.example'], $zones[102], 'a named row keyed by the domain id keeps the copy');
        $this->assertSame([100, null], $zones[101], 'and that zone keeps its extra owner unnamed');
    }

    /** @return array<string, array{string, string}> */
    /** Backend marker value after the backfill, false when the row is gone. */
    private function runWithApiMarker(PDO $db, string $engine): string|false
    {
        $db->exec("INSERT INTO app_settings (setting_key, setting_value) VALUES ('backend.zone_ids', 'api'), ('other.setting', 'api')");
        $db->exec($this->backfillSql($engine));
        $this->assertSame('api', $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'other.setting'")->fetchColumn());

        return $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'backend.zone_ids'")->fetchColumn();
    }

    #[DataProvider('engines')]
    public function testTheApiMarkerIsClearedOnceNoRowIsKeyedByAnotherId(string $engine): void
    {
        $db = $this->connect($engine);
        $this->createTables($db, $engine);
        // The 4.5 zone sync added copy 20 and marked the database; row 10 has no domain_id
        $db->exec('DELETE FROM zones WHERE id = 10');
        $db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_name) VALUES (20, 20, 0, '', 'one.example')");

        $this->assertFalse($this->runWithApiMarker($db, $engine), 'nothing written in API mode is left');
        $this->assertArrayNotHasKey(20, $this->zones($db));
    }

    #[DataProvider('engines')]
    public function testTheApiMarkerStaysWhileARowIsKeyedByAnotherId(string $engine): void
    {
        $db = $this->connect($engine);
        $this->createTables($db, $engine);
        $db->exec('DELETE FROM zones WHERE id = 10');
        $db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_name) VALUES (20, 20, 0, '', 'one.example')");
        $db->exec('INSERT INTO zones_groups (id, domain_id, group_id) VALUES (3, 20, 1)');

        $this->assertSame('api', $this->runWithApiMarker($db, $engine), 'a copy with a group grant keeps the marker');
    }

    #[DataProvider('engines')]
    public function testTheApiMarkerStaysWhileARowHasNoDomainId(string $engine): void
    {
        $db = $this->connect($engine);
        $this->createTables($db, $engine);

        $this->assertSame('api', $this->runWithApiMarker($db, $engine), 'row 10 has no domain_id');
    }

    public static function domainsCollations(): array
    {
        return ['unicode_ci' => ['utf8mb4_unicode_ci', 'three.example'], 'bin, name held in another case' => ['utf8mb4_bin', 'Three.example']];
    }

    #[DataProvider('domainsCollations')]
    public function testADomainsTableWithAnotherCollationIsComparedInTheZonesCollation(string $collation, string $threeName): void
    {
        $db = $this->connect('mysql');
        $this->createTables($db, 'mysql', true, $collation, $threeName);

        $db->exec($this->backfillSql('mysql'));
        $zones = $this->zones($db);

        $this->assertSame('one.example', $zones[1][1]);
        $this->assertNull($zones[4][1], 'a name held by another domain_id is skipped');
        $this->assertSame('four.example', $zones[6][1]);
        $this->assertCount(1, array_filter($zones, static fn(array $z): bool => $z[1] === 'five.example'));
    }

    #[DataProvider('engines')]
    public function testRunningTheBackfillTwiceChangesNothing(string $engine): void
    {
        $db = $this->connect($engine);
        $this->createTables($db, $engine);

        $db->exec($this->backfillSql($engine));
        $afterFirst = $this->zones($db);
        $db->exec($this->backfillSql($engine));

        $this->assertSame($afterFirst, $this->zones($db));
    }

    #[DataProvider('engines')]
    public function testMissingDomainsTableIsSkippedWithoutError(string $engine): void
    {
        if ($engine === 'sqlite') {
            $this->markTestSkipped('SQLite cannot skip a statement; its backfill is a separate file for SQL backend databases');
        }
        $db = $this->connect($engine);
        $this->createTables($db, $engine, false);
        $before = $this->zones($db);

        $db->exec($this->backfillSql($engine));

        $this->assertSame($before, $this->zones($db));
    }

    public function testThePostgresqlBackfillRunsWithoutTheTempPrivilege(): void
    {
        $name = self::scratch() . '_notemp';
        $dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=';
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            $admin = new PDO($dsn . 'pdns', 'pdns', 'poweradmin', $options);
        } catch (PDOException $e) {
            $this->markTestSkipped('pgsql is not reachable: ' . $e->getMessage());
        }

        // A scratch database, so revoking TEMP from PUBLIC leaves the shared one untouched
        $admin->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
        $admin->exec("DROP ROLE IF EXISTS $name");
        $admin->exec("CREATE ROLE $name LOGIN PASSWORD 'notemp'");
        $admin->exec("CREATE DATABASE $name");
        try {
            $admin->exec("REVOKE ALL ON DATABASE $name FROM PUBLIC");
            $admin->exec("GRANT CONNECT ON DATABASE $name TO $name");
            (new PDO($dsn . $name, 'pdns', 'poweradmin', $options))->exec("GRANT USAGE, CREATE ON SCHEMA public TO $name");

            $db = new PDO($dsn . $name, $name, 'notemp', $options);
            $this->assertFalse((bool)$db->query("SELECT has_database_privilege(current_database(), 'TEMP')")->fetchColumn());
            $this->createTables($db, 'pgsql');

            $db->exec($this->backfillSql('pgsql'));

            $this->assertSame('one.example', $this->zones($db)[1][1]);
            $this->assertNull($db->query("SELECT to_regclass('poweradmin_460_zone_copies')")->fetchColumn(), 'the work table is dropped');
            $db = null;
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
            $admin->exec("DROP ROLE IF EXISTS $name");
        }
    }
}
