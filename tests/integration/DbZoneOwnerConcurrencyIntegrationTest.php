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
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipRefusal;
use Poweradmin\Infrastructure\Database\BackendModeMarker;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use TestHelpers\FakeConfiguration;

/**
 * Owners added to and removed from one zone at the same moment on the SQL backend.
 * The writers run as separate processes against a scratch database, because a lock
 * conflict needs two connections.
 *
 * Needs pcntl and the devcontainer (MariaDB, PostgreSQL); skipped otherwise.
 */
class DbZoneOwnerConcurrencyIntegrationTest extends TestCase
{
    private const MYSQL_SCRATCH = 'poweradmin_it_sql_owners';
    private const PGSQL_SCHEMA = 'poweradmin_it_sql_owners';
    private const ROUNDS = 40;
    private const ZONE_ID = 1;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available');
        }
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::MYSQL_SCRATCH);
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::PGSQL_SCHEMA . ' CASCADE');
        } catch (PDOException) {
        }
    }

    private static function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if ($engine === 'mysql') {
            return new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . self::MYSQL_SCRATCH, 'root', 'uberuser', $options);
        }
        $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
        $db->exec('SET search_path TO ' . self::PGSQL_SCHEMA);

        return $db;
    }

    /**
     * A scratch SQL-mode install: zone 1 owned by user 1, plus extra ownership rows for $extraOwners.
     *
     * @param list<int> $extraOwners
     */
    private function createScratch(string $engine, array $extraOwners = []): void
    {
        try {
            if ($engine === 'mysql') {
                $root = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $root->exec('DROP DATABASE IF EXISTS ' . self::MYSQL_SCRATCH);
                $root->exec('CREATE DATABASE ' . self::MYSQL_SCRATCH);
                foreach (['zones', 'zones_groups', 'api_key_zones', 'app_settings', 'users'] as $table) {
                    $root->exec('CREATE TABLE ' . self::MYSQL_SCRATCH . ".$table LIKE poweradmin.$table");
                }
                foreach (['record_comment_links', 'records_zone_templ', 'records_zone_templ_api', 'perm_templ', 'perm_templ_items', 'perm_items', 'user_groups', 'user_group_members'] as $table) {
                    $root->exec('CREATE TABLE ' . self::MYSQL_SCRATCH . ".$table LIKE poweradmin.$table");
                }
                foreach (['domains', 'records', 'comments', 'domainmetadata', 'cryptokeys'] as $table) {
                    $root->exec('CREATE TABLE ' . self::MYSQL_SCRATCH . ".$table LIKE pdns.$table");
                }
                $db = self::connect('mysql');
            } else {
                $admin = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $admin->exec('DROP SCHEMA IF EXISTS ' . self::PGSQL_SCHEMA . ' CASCADE');
                $admin->exec('CREATE SCHEMA ' . self::PGSQL_SCHEMA);
                $db = self::connect('pgsql');
                $db->exec("CREATE TABLE zones (id SERIAL PRIMARY KEY, domain_id INT NULL, owner INT NULL, comment VARCHAR(1024) NULL,
                    zone_templ_id INT NOT NULL DEFAULT 0, zone_name VARCHAR(255) NULL, zone_type VARCHAR(8) NULL, zone_master VARCHAR(255) NULL)");
                $db->exec("CREATE UNIQUE INDEX idx_it_zone_name ON zones (zone_name)");
                $db->exec("CREATE INDEX idx_it_zones_domain_id ON zones (domain_id)");
                $db->exec("CREATE TABLE zones_groups (id SERIAL PRIMARY KEY, domain_id INT NOT NULL, group_id INT NOT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL)");
                $db->exec("CREATE TABLE api_key_zones (id SERIAL PRIMARY KEY, api_key_id INT NOT NULL, zone_id INT NOT NULL)");
                $db->exec("CREATE TABLE users (id SERIAL PRIMARY KEY, username VARCHAR(64) NOT NULL, password VARCHAR(128) NOT NULL, fullname VARCHAR(255) NULL, email VARCHAR(255) NULL, description VARCHAR(1024) NULL)");
                $db->exec("CREATE TABLE domains (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL UNIQUE, master VARCHAR(128) NULL,
                    last_check INT NULL, type VARCHAR(8) NOT NULL, notified_serial BIGINT NULL, account VARCHAR(40) NULL, options TEXT NULL, catalog VARCHAR(255) NULL)");
                // Only the columns zone deletion touches
                $db->exec("CREATE TABLE records (id SERIAL PRIMARY KEY, domain_id INT NULL)");
                $db->exec("CREATE TABLE comments (id SERIAL PRIMARY KEY, domain_id INT NOT NULL)");
                $db->exec("CREATE TABLE domainmetadata (id SERIAL PRIMARY KEY, domain_id INT NULL)");
                $db->exec("CREATE TABLE cryptokeys (id SERIAL PRIMARY KEY, domain_id INT NULL)");
                $db->exec("CREATE TABLE record_comment_links (record_id INT NOT NULL, comment_id INT NOT NULL)");
                $db->exec("CREATE TABLE records_zone_templ (domain_id INT NOT NULL, record_id INT NOT NULL, zone_templ_id INT NOT NULL)");
                $db->exec("CREATE TABLE records_zone_templ_api (domain_id INT NOT NULL, record_id VARCHAR(255) NOT NULL, zone_templ_id INT NOT NULL)");
                $db->exec("CREATE TABLE app_settings (setting_key VARCHAR(128) NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL, value_type VARCHAR(16) NOT NULL DEFAULT 'string')");
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        $db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'a.example', 'MASTER'), (2, 'b.example', 'MASTER'), (3, 'c.example', 'MASTER')");
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 1, 1, 'a.example'), (2, 2, 1, 'b.example'), (3, 3, 1, 'c.example')");
        $db->exec("INSERT INTO app_settings (setting_key, setting_value, value_type) VALUES ('" . BackendModeMarker::MARKER . "', 'sql', 'string')");
        if ($engine === 'mysql') {
            // Only id, username and fullname matter here; the other NOT NULL user columns may default
            $db->exec("SET SESSION sql_mode = ''");
        }
        foreach ([1, 10, 11, 20] as $userId) {
            $db->exec("INSERT INTO users (id, username, password, fullname, email, description) VALUES ($userId, 'user$userId', 'x', 'User $userId', 'u$userId@example.com', '')");
        }
        if ($engine === 'pgsql') {
            $db->exec("SELECT setval('zones_id_seq', 3)");
        }
        foreach ($extraOwners as $userId) {
            $db->exec("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (" . self::ZONE_ID . ", $userId, 0)");
        }
    }

    private function repository(PDO $db, string $engine): DbZoneRepository
    {
        $config = new FakeConfiguration([
            'database' => ['type' => $engine, 'pdns_db_name' => ''],
            'dns' => ['zone_ownership_mode' => 'both'],
        ]);

        return new DbZoneRepository($db, $config);
    }

    private function guard(PDO $db, string $engine, DbZoneRepository $repository): ZoneOwnershipGuard
    {
        $config = new FakeConfiguration(['database' => ['type' => $engine, 'pdns_db_name' => ''], 'dns' => ['zone_ownership_mode' => 'both']]);

        return new ZoneOwnershipGuard(
            $repository,
            new DbZoneGroupRepository($db, $config, true),
            new ZoneOwnershipModeService($config),
            new PdoTransaction($db)
        );
    }

    /**
     * Runs $work in two forked processes released at the same instant.
     *
     * @param callable(int): mixed $work Gets the process index; its return value comes back JSON-encoded
     * @return list<array{ok: bool, value: mixed, error: string}>
     */
    private function inTwoProcesses(callable $work): array
    {
        $go = microtime(true) + 0.3;
        $children = [];
        foreach ([0, 1] as $index) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($pair[0]);
                while (microtime(true) < $go) {
                }
                try {
                    $reply = ['ok' => true, 'value' => $work($index), 'error' => ''];
                } catch (\Throwable $e) {
                    $reply = ['ok' => false, 'value' => null, 'error' => get_class($e) . ': ' . $e->getMessage()];
                }
                fwrite($pair[1], json_encode($reply));
                fclose($pair[1]);
                // No shutdown: the parent's connections and PHPUnit state are not this process's to close
                posix_kill(getmypid(), SIGKILL);
            }
            fclose($pair[1]);
            $children[] = [$pid, $pair[0]];
        }

        $replies = [];
        foreach ($children as [$pid, $socket]) {
            $replies[] = json_decode((string)stream_get_contents($socket), true) ?? ['ok' => false, 'value' => null, 'error' => 'no reply'];
            fclose($socket);
            pcntl_waitpid($pid, $status);
        }

        return $replies;
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    /** @return list<int> user ids owning zone 1 through extra rows, one entry per row */
    private function extraOwnerRows(PDO $db): array
    {
        $rows = $db->query("SELECT owner FROM zones WHERE zone_name IS NULL AND domain_id = " . self::ZONE_ID . " ORDER BY owner")->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $rows);
    }

    /**
     * @param list<array{ok: bool, value: mixed, error: string}> $replies
     * @return list<string>
     */
    private static function errors(array $replies): array
    {
        return array_values(array_filter(array_map(static fn(array $reply): string => $reply['error'], $replies)));
    }

    #[DataProvider('engines')]
    public function testTwoDifferentUsersAddedToOneZoneAtOnce(string $engine): void
    {
        $failures = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->createScratch($engine);
            $replies = $this->inTwoProcesses(function (int $index) use ($engine): bool {
                $repository = $this->repository(self::connect($engine), $engine);
                $userId = 10 + $index;

                return $repository->isUserZoneOwner(self::ZONE_ID, $userId) || $repository->addOwnerToZone(self::ZONE_ID, $userId);
            });
            $failures = [...$failures, ...self::errors($replies)];
            $this->assertSame([10, 11], $this->extraOwnerRows(self::connect($engine)));
        }

        $this->assertSame([], $failures, count($failures) . ' of ' . (self::ROUNDS * 2) . ' concurrent owner additions failed');
    }

    #[DataProvider('engines')]
    public function testTheSameUserAddedToOneZoneTwiceAtOnceGetsOneRow(string $engine): void
    {
        $failures = [];
        $duplicates = 0;
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->createScratch($engine);
            $replies = $this->inTwoProcesses(function () use ($engine): bool {
                $repository = $this->repository(self::connect($engine), $engine);

                return $repository->isUserZoneOwner(self::ZONE_ID, 10) || $repository->addOwnerToZone(self::ZONE_ID, 10);
            });
            $failures = [...$failures, ...self::errors($replies)];
            $duplicates += count($this->extraOwnerRows(self::connect($engine))) > 1 ? 1 : 0;
        }

        $this->assertSame([], $failures);
        $this->assertSame(0, $duplicates, "$duplicates of " . self::ROUNDS . ' rounds stored the owner twice');
    }

    #[DataProvider('engines')]
    public function testAnOwnerAddedWhileAnotherIsRemovedKeepsBothOutcomes(string $engine): void
    {
        $failures = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->createScratch($engine, [20]);
            $replies = $this->inTwoProcesses(function (int $index) use ($engine): bool {
                $db = self::connect($engine);
                $repository = $this->repository($db, $engine);
                if ($index === 0) {
                    return $this->guard($db, $engine, $repository)->removeUserOwner(self::ZONE_ID, 20) === true;
                }

                return $repository->isUserZoneOwner(self::ZONE_ID, 10) || $repository->addOwnerToZone(self::ZONE_ID, 10);
            });
            $failures = [...$failures, ...self::errors($replies)];
            $this->assertSame([], $failures);
            $this->assertSame([10], $this->extraOwnerRows(self::connect($engine)));
        }

        $this->assertSame([], $failures, count($failures) . ' of ' . (self::ROUNDS * 2) . ' concurrent writes failed');
    }

    #[DataProvider('engines')]
    public function testTwoOwnersRemovedFromOneZoneAtOnceNeverLeaveItUnowned(string $engine): void
    {
        $failures = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->createScratch($engine, [20]);
            // Owners: the zone's own row (user 1) and one extra row (user 20)
            $replies = $this->inTwoProcesses(function (int $index) use ($engine): string {
                $db = self::connect($engine);
                $result = $this->guard($db, $engine, $this->repository($db, $engine))->removeUserOwner(self::ZONE_ID, $index === 0 ? 1 : 20);

                return $result instanceof ZoneOwnershipRefusal ? 'refused' : ($result ? 'removed' : 'absent');
            });
            $failures = [...$failures, ...self::errors($replies)];
            $this->assertSame([], $failures);
            $outcomes = array_column($replies, 'value');
            sort($outcomes);
            $this->assertSame(['refused', 'removed'], $outcomes, 'Exactly one removal may succeed');
            $owners = $this->repository(self::connect($engine), $engine)->getZoneOwners(self::ZONE_ID);
            $this->assertCount(1, $owners, 'The zone must keep exactly one owner');
        }

        $this->assertSame([], $failures, count($failures) . ' of ' . (self::ROUNDS * 2) . ' concurrent removals failed');
    }

    /** Metadata rows are deleted after the zones rows, which widens the window between the two deletes. */
    private function addMetadataRows(string $engine, int $count): void
    {
        $db = self::connect($engine);
        $columns = $engine === 'mysql' ? '(domain_id, kind, content)' : '(domain_id)';
        $row = $engine === 'mysql' ? "(" . self::ZONE_ID . ", 'X', 'y')" : '(' . self::ZONE_ID . ')';
        foreach (array_chunk(array_fill(0, $count, $row), 5000) as $chunk) {
            $db->exec("INSERT INTO domainmetadata $columns VALUES " . implode(',', $chunk));
        }
    }

    #[DataProvider('engines')]
    public function testDeletingAZoneWhileAnOwnerIsAddedNeitherDeadlocksNorLeavesAnOrphanRow(string $engine): void
    {
        $failures = [];
        $orphans = 0;
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->createScratch($engine, [20]);
            $this->addMetadataRows($engine, 20000);
            $replies = $this->inTwoProcesses(function (int $index) use ($engine): bool {
                $repository = $this->repository(self::connect($engine), $engine);
                if ($index === 1) {
                    // The add lands anywhere from before the delete starts to after it finishes
                    usleep(random_int(0, 40000));
                }

                return $index === 0 ? $repository->deleteZone(self::ZONE_ID) : $repository->addOwnerToZone(self::ZONE_ID, 10);
            });
            $failures = [...$failures, ...self::errors($replies)];
            $this->assertTrue($replies[0]['value'] ?? false, 'The delete must succeed, not lose a deadlock: ' . json_encode($replies));
            $left = self::connect($engine)->query('SELECT COUNT(*) FROM zones WHERE domain_id = ' . self::ZONE_ID)->fetchColumn();
            $orphans += (int)$left > 0 ? 1 : 0;
        }

        $this->assertSame([], $failures);
        $this->assertSame(0, $orphans, "$orphans of " . self::ROUNDS . ' rounds left a zones row for the deleted zone');
    }

    public function testAnOwnerCannotBeAddedToAZoneWithNoDomainsRow(): void
    {
        $this->createScratch('mysql');
        $db = self::connect('mysql');
        $repository = $this->repository($db, 'mysql');

        $this->assertFalse($repository->addOwnerToZone(99, 10));
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM zones WHERE domain_id = 99')->fetchColumn());
    }

    public function testTheSameOwnerAddedInAndOutsideTheLimitsTransactionGetsOneRow(): void
    {
        // The limit's transaction reads the zone's ownership first, fixing the MySQL snapshot; the owner row
        // the other request commits while it waits on the lock must still be seen
        $duplicates = 0;
        $failures = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->createScratch('mysql');
            self::connect('mysql')->exec('UPDATE users SET max_zones = 100 WHERE id = 10');
            $replies = $this->inTwoProcesses(function (int $index): bool {
                $db = self::connect('mysql');
                $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $repository = $this->repository($db, 'mysql');
                if ($index === 1) {
                    usleep(random_int(0, 20000));

                    return $repository->addOwnerToZone(self::ZONE_ID, 11);
                }
                $config = new FakeConfiguration(['database' => ['type' => 'mysql', 'pdns_db_name' => ''], 'dns' => []]);
                $users = new DbUserRepository($db, $config, false);
                $limit = new ZoneOwnershipLimit($users, new DbUserGroupRepository($db), new DbZoneGroupRepository($db, $config, false), new PermissionService($users), $config, new PdoTransaction($db));
                $written = $limit->reassignZones([10 => [self::ZONE_ID]], static fn(): bool => $repository->addOwnerToZone(self::ZONE_ID, 11));

                return $written === true;
            });
            $failures = [...$failures, ...self::errors($replies)];
            $duplicates += count(array_keys($this->extraOwnerRows(self::connect('mysql')), 11)) > 1 ? 1 : 0;
        }

        $this->assertSame([], $failures);
        $this->assertSame(0, $duplicates, "$duplicates of " . self::ROUNDS . ' rounds stored the owner twice');
    }
}
