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
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\ApiStatusInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Dns\DefaultSoaBuilder;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\CanonicalZoneIdAllocator;
use Poweradmin\Infrastructure\Database\SharedZoneIds;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Service\ApiKeyZoneScopeMigration;
use Poweradmin\Infrastructure\Service\Consistency\ApiConsistencyChecks;
use Poweradmin\Infrastructure\Service\Consistency\ZoneOwnerRepair;
use Psr\Log\NullLogger;

/**
 * The zone id handling of API backend mode (shared ids, the canonical id allocator, the API
 * key scope move and the consistency checks) on every engine Poweradmin supports. Each
 * engine gets throwaway tables in its own scratch database or schema; MySQL and PostgreSQL
 * run when the devcontainer is up and are skipped otherwise.
 */
class ZoneIdCrossEngineIntegrationTest extends TestCase
{
    /** Per-process names, so parallel suite runs do not drop each other's scratch data. */
    private static function pgsqlSchema(): string
    {
        return 'poweradmin_it_' . getmypid();
    }

    private static function mysqlDb(): string
    {
        return 'poweradmin_it_' . getmypid();
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
                // pdns may not create databases; the devcontainer root may
                $db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options);
                $db->exec('CREATE DATABASE IF NOT EXISTS ' . self::mysqlDb());
                $db->exec('USE ' . self::mysqlDb());
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

        foreach (['zones', 'zones_groups', 'user_groups', 'user_group_members', 'api_key_zones', 'app_settings'] as $table) {
            $db->exec("DROP TABLE IF EXISTS $table");
        }
        $id = match ($engine) {
            'mysql' => 'id INT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'id SERIAL PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY',
        };
        $db->exec("CREATE TABLE zones ($id, domain_id INT NULL, owner INT NULL, comment VARCHAR(1024) NULL,
            zone_templ_id INT NOT NULL DEFAULT 0, zone_name VARCHAR(255) NULL, zone_type VARCHAR(8) NULL, zone_master VARCHAR(255) NULL)");
        $db->exec("CREATE UNIQUE INDEX idx_it_zone_name ON zones (zone_name)");
        $db->exec("CREATE TABLE zones_groups ($id, domain_id INT NOT NULL, group_id INT NOT NULL)");
        $db->exec("CREATE TABLE user_groups ($id, name VARCHAR(255) NOT NULL)");
        $db->exec("CREATE TABLE user_group_members ($id, group_id INT NOT NULL, user_id INT NOT NULL)");
        $db->exec("CREATE TABLE api_key_zones ($id, api_key_id INT NOT NULL, zone_id INT NOT NULL)");
        $db->exec("CREATE TABLE app_settings (setting_key VARCHAR(128) NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL, value_type VARCHAR(16) NOT NULL DEFAULT 'string')");

        // Zone 5 created in API mode, migrated zone 12 with domain_id 5 (shared id 5), an extra
        // owner and a group grant on 5, migrated zone 14 (canonical 12) and a plain zone 30
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES
            (5, 5, 1, 'created.example'), (12, 5, 2, 'migrated.example'), (13, 5, 3, NULL),
            (14, 12, 2, 'reverse.example'), (30, 30, 2, 'plain.example')");
        $db->exec("INSERT INTO user_groups (id, name) VALUES (7, 'Editors')");
        $db->exec("INSERT INTO user_group_members (group_id, user_id) VALUES (7, 4)");
        // 5 is shared; 14 is reverse.example's row id and names no zone canonically
        $db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (5, 7), (14, 7)");
        if ($engine === 'pgsql') {
            $db->exec("SELECT setval('zones_id_seq', 30)");
        }

        return $db;
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::mysqlDb());
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::pgsqlSchema() . ' CASCADE');
        } catch (PDOException) {
        }
    }

    #[DataProvider('engines')]
    public function testSharedIdsAndOwnershipAgreeOnEveryEngine(string $engine): void
    {
        $db = $this->connect($engine);
        $users = new DbUserRepository($db, ConfigurationManager::getInstance(), true);

        $this->assertSame([5], SharedZoneIds::all($db));
        $this->assertTrue(SharedZoneIds::isShared($db, 5));
        $this->assertSame([5 => 'created.example'], SharedZoneIds::openedNames($db));
        $this->assertTrue($users->userOwnsZone(1, 5));
        $this->assertFalse($users->userOwnsZone(2, 5), 'The migrated zone owner does not reach the created zone');
        $this->assertFalse($users->userOwnsZone(3, 5), 'The extra owner on a shared id counts for nothing');
        $this->assertFalse($users->userOwnsZone(4, 5), 'The group grant on a shared id counts for nothing');
        $this->assertTrue($users->userOwnsZone(2, 30));

        $stmt = $db->prepare("SELECT zone_name FROM zones WHERE zone_name IS NOT NULL AND id NOT IN (" . SharedZoneIds::sharedIdsSql() . ") ORDER BY zone_name");
        $stmt->execute();
        $this->assertSame(['migrated.example', 'plain.example', 'reverse.example'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    #[DataProvider('engines')]
    public function testANewZoneMovesOffATakenIdAndTheNextKeepsItsOwn(string $engine): void
    {
        $db = $this->connect($engine);
        // The next row id equals a used id: make 31 a scope value
        $db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (9, 31)");

        $db->beginTransaction();
        $allocator = new CanonicalZoneIdAllocator($db);
        $db->exec("INSERT INTO zones (domain_id, owner, zone_name, comment) VALUES (NULL, 1, 'first.example', 'note')");
        $moved = $allocator->settle((int)$db->lastInsertId($engine === 'pgsql' ? 'zones_id_seq' : null));
        $db->commit();

        $db->beginTransaction();
        $allocator = new CanonicalZoneIdAllocator($db);
        $db->exec("INSERT INTO zones (domain_id, zone_name) VALUES (NULL, 'second.example')");
        $rowId = (int)$db->lastInsertId($engine === 'pgsql' ? 'zones_id_seq' : null);
        $next = $allocator->settle($rowId);
        $db->commit();

        $this->assertSame(32, $moved);
        $row = $db->query("SELECT id, domain_id, owner, comment FROM zones WHERE zone_name = 'first.example'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([32, 32, 1, 'note'], [(int)$row['id'], (int)$row['domain_id'], (int)$row['owner'], $row['comment']]);
        $this->assertSame(33, $rowId, 'The id counter moved past the moved row');
        $this->assertSame(33, $next);
    }

    #[DataProvider('engines')]
    public function testTheScopeMoveRunsOnceOnEveryEngine(string $engine): void
    {
        $db = $this->connect($engine);
        // Row ids 14 (canonical 12), 30 (self) and 12 (canonical 5, trusted as a row id); once
        // row 5 is gone, 5 is no row id but still row 12's canonical id, so it matches nothing
        $db->exec("DELETE FROM zones WHERE id = 5");
        $db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (1, 14), (1, 30), (2, 12), (3, 5)");

        $this->assertTrue((new ApiKeyZoneScopeMigration($db, true, new NullLogger()))->runOnce());
        $this->assertTrue((new ApiKeyZoneScopeMigration($db, true, new NullLogger()))->runOnce());

        $rows = $db->query("SELECT api_key_id, zone_id FROM api_key_zones ORDER BY api_key_id, zone_id")->fetchAll(PDO::FETCH_NUM);
        $this->assertSame([[1, 12], [1, 30], [2, 5], [3, 0]], array_map(fn(array $r): array => array_map('intval', $r), $rows));
    }

    #[DataProvider('engines')]
    public function testTheConsistencyQueriesRunOnEveryEngine(string $engine): void
    {
        $db = $this->connect($engine);
        $checks = new ApiConsistencyChecks($db, $this->createStub(DnsBackendProviderInterface::class), $this->createStub(ApiStatusInterface::class), new ZoneOwnerRepair($db), new DefaultSoaBuilder($this->createStub(ConfigurationInterface::class)));

        $this->assertSame([['id' => 5, 'names' => 'created.example, migrated.example', 'ignored_owners' => 1, 'ignored_groups' => 1]], $checks->checkSharedZoneIds()['data']);
        $this->assertSame([['id' => 14, 'group' => 'Editors', 'row_zone' => 'reverse.example']], $checks->checkGroupGrantsOnRowIds()['data']);
    }
}
