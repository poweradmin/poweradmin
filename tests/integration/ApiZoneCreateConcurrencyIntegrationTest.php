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
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Database\BackendModeMarker;
use Poweradmin\Infrastructure\Database\CanonicalZoneIdAllocator;
use Poweradmin\Infrastructure\Database\DeadlockRetry;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * Two zones created at the same moment on the API backend. The creators run as separate
 * processes against a scratch database, because a deadlock needs two connections.
 *
 * Needs pcntl and the devcontainer (MariaDB, PostgreSQL, PowerDNS); skipped otherwise.
 */
class ApiZoneCreateConcurrencyIntegrationTest extends TestCase
{
    private const MYSQL_SCRATCH = 'poweradmin_it_api_concurrency';
    private const PGSQL_SCHEMA = 'poweradmin_it_api_concurrency';
    private const PDNS_API_URL = 'http://localhost:8181';
    private const PDNS_API_KEY = 'fxiBmBFx7MITw5ECRMOr10ghlxGMvWZA';
    private const ROUNDS = 25;

    /** @var list<string> */
    private array $createdZones = [];

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available');
        }
    }

    protected function tearDown(): void
    {
        try {
            $client = new PowerdnsApiClient(new HttpClient(self::PDNS_API_URL, self::PDNS_API_KEY), 'localhost');
            foreach ($this->createdZones as $name) {
                $client->deleteZone(new Zone($name . '.'));
            }
        } catch (\Throwable) {
        }
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::MYSQL_SCRATCH);
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::PGSQL_SCHEMA . ' CASCADE');
        } catch (PDOException) {
        }
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
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

    /** Builds an empty scratch database shaped like an API-mode install with a few zones and the marker. */
    private function createScratch(string $engine, bool $withMarker = true): void
    {
        try {
            if ($engine === 'mysql') {
                $root = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $root->exec('DROP DATABASE IF EXISTS ' . self::MYSQL_SCRATCH);
                $root->exec('CREATE DATABASE ' . self::MYSQL_SCRATCH);
                foreach (['zones', 'zones_groups', 'api_key_zones', 'app_settings'] as $table) {
                    $root->exec('CREATE TABLE ' . self::MYSQL_SCRATCH . ".$table LIKE poweradmin.$table");
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
                $db->exec("CREATE TABLE zones_groups (id SERIAL PRIMARY KEY, domain_id INT NOT NULL, group_id INT NOT NULL)");
                $db->exec("CREATE TABLE api_key_zones (id SERIAL PRIMARY KEY, api_key_id INT NOT NULL, zone_id INT NOT NULL)");
                $db->exec("CREATE TABLE app_settings (setting_key VARCHAR(128) NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL, value_type VARCHAR(16) NOT NULL DEFAULT 'string')");
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 1, 1, 'a.example'), (2, 2, 1, 'b.example'), (3, 3, 1, 'c.example')");
        if ($engine === 'pgsql') {
            $db->exec("SELECT setval('zones_id_seq', 3)");
        }
        if ($withMarker) {
            $db->exec("INSERT INTO app_settings (setting_key, setting_value, value_type) VALUES ('" . BackendModeMarker::MARKER . "', 'api', 'string')");
        }
    }

    /**
     * Runs $work in two forked processes released at the same instant.
     *
     * @param callable(int): mixed $work Gets the process index; its return value comes back JSON-encoded
     * @return list<array{ok: bool, value: mixed, error: string}>
     */
    private function inTwoProcesses(callable $work): array
    {
        $go = microtime(true) + 0.4;
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

    #[DataProvider('engines')]
    public function testParallelAllocationNeverDeadlocksAndGivesDistinctIds(string $engine): void
    {
        $failures = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->createScratch($engine);
            // No retry here: the lock order alone has to keep the creators apart
            $replies = $this->inTwoProcesses(function (int $index) use ($engine, $round): int {
                $db = self::connect($engine);
                $db->beginTransaction();
                $allocator = new CanonicalZoneIdAllocator($db);
                $insert = $db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id, zone_name, zone_type, zone_master) VALUES (NULL, NULL, 0, :name, 'NATIVE', '')");
                $insert->execute([':name' => "p$index-$round.example"]);
                $zoneId = $allocator->settle((int)$db->lastInsertId('zones_id_seq'));
                $db->commit();

                return $zoneId;
            });

            $ids = [];
            foreach ($replies as $reply) {
                $reply['ok'] ? $ids[] = $reply['value'] : $failures[] = $reply['error'];
            }
            $this->assertSame($ids, array_values(array_unique($ids)), 'Parallel creators were given the same zone id');
        }

        $this->assertSame([], $failures, count($failures) . ' of ' . (self::ROUNDS * 2) . ' parallel creations failed');
    }

    /** @return array<string, array{string}> */
    public static function isolationLevels(): array
    {
        return ['repeatable read' => ['REPEATABLE READ'], 'read committed' => ['READ COMMITTED']];
    }

    #[DataProvider('isolationLevels')]
    public function testTheVeryFirstApiWriteRacingForTheMarkerIsRetried(string $isolation): void
    {
        // Without a marker row there is no row to queue on: MySQL may pick a deadlock victim, and
        // under READ COMMITTED the locking read takes no gap lock so both creators try the insert
        $failures = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->createScratch('mysql', false);
            $replies = $this->inTwoProcesses(function (int $index) use ($round, $isolation): int {
                $db = self::connect('mysql');
                $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);

                return DeadlockRetry::run(static function () use ($db, $index, $round): int {
                    $db->beginTransaction();
                    try {
                        $allocator = new CanonicalZoneIdAllocator($db);
                        $insert = $db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id, zone_name, zone_type, zone_master) VALUES (NULL, NULL, 0, :name, 'NATIVE', '')");
                        $insert->execute([':name' => "f$index-$round.example"]);
                        $zoneId = $allocator->settle((int)$db->lastInsertId('zones_id_seq'));
                        $db->commit();

                        return $zoneId;
                    } catch (\Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        throw $e;
                    }
                });
            });
            foreach ($replies as $reply) {
                if (!$reply['ok']) {
                    $failures[] = $reply['error'];
                }
            }
        }

        $this->assertSame([], $failures);
    }

    private function providerFor(PDO $db): ApiDnsBackendProvider
    {
        $client = new PowerdnsApiClient(new HttpClient(self::PDNS_API_URL, self::PDNS_API_KEY), 'localhost');
        $config = new FakeConfiguration([
            'pdns_api' => ['url' => self::PDNS_API_URL, 'key' => self::PDNS_API_KEY, 'server_name' => 'localhost', 'backend' => 'api'],
            'database' => ['pdns_db_name' => ''],
        ]);

        return new ApiDnsBackendProvider($client, $db, $config, new NullLogger());
    }

    private function requirePowerDns(): void
    {
        $ch = curl_init(self::PDNS_API_URL . '/api/v1/servers/localhost');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => ['X-API-Key: ' . self::PDNS_API_KEY]]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            $this->markTestSkipped('PowerDNS API not available at ' . self::PDNS_API_URL);
        }
    }

    public function testParallelZoneCreationSucceedsForBothCreators(): void
    {
        $this->requirePowerDns();

        $failures = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->createScratch('mysql');
            $prefix = 'conc-' . uniqid();
            $replies = $this->inTwoProcesses(function (int $index) use ($prefix): int {
                $zone = "$prefix-$index.example.com";

                return (int)$this->providerFor(self::connect('mysql'))->createZone($zone, 'NATIVE');
            });
            foreach ([0, 1] as $index) {
                $this->createdZones[] = "$prefix-$index.example.com";
            }

            $ids = [];
            foreach ($replies as $reply) {
                $reply['ok'] && $reply['value'] > 0 ? $ids[] = $reply['value'] : $failures[] = $reply['error'] ?: 'createZone returned false';
            }
            $this->assertSame($ids, array_values(array_unique($ids)));
        }

        $this->assertSame([], $failures);
    }

    public function testAZoneIsNotLeftInPowerDnsWhenItsLocalRowCannotBeStored(): void
    {
        $this->requirePowerDns();
        $this->createScratch('mysql');
        $db = self::connect('mysql');
        // Not retryable: the insert fails for good after PowerDNS has accepted the zone
        $db->exec('DROP TABLE api_key_zones');

        $zone = 'orphan-' . uniqid() . '.example.com';
        $this->createdZones[] = $zone;
        try {
            $this->providerFor($db)->createZone($zone, 'NATIVE');
            $this->fail('createZone() should have thrown');
        } catch (PDOException) {
        }

        $this->assertFalse($this->zoneExists($zone), 'The zone was left behind in PowerDNS');
    }

    private function zoneExists(string $name): bool
    {
        $ch = curl_init(self::PDNS_API_URL . '/api/v1/servers/localhost/zones/' . rawurlencode($name . '.'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => ['X-API-Key: ' . self::PDNS_API_KEY]]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code === 200;
    }
}
