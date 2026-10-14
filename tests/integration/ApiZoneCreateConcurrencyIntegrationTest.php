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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;

/**
 * Zones created at the same moment on the API backend. The creators run as separate
 * processes against a scratch database, because a deadlock needs several connections.
 * PowerDNS is stubbed: only the local zones row is under test.
 *
 * Needs pcntl and the devcontainer databases for MySQL/MariaDB and PostgreSQL; skipped otherwise.
 */
#[Group('concurrency')]
class ApiZoneCreateConcurrencyIntegrationTest extends TestCase
{
    private const ROUNDS = 10;

    /** Per-process name, so parallel suite runs do not drop each other's scratch data; set before forking. */
    private static string $scratch = '';

    private static function scratch(): string
    {
        return self::$scratch;
    }

    private static function sqliteFile(): string
    {
        return sys_get_temp_dir() . '/' . self::scratch() . '.sqlite';
    }

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available');
        }
        self::$scratch = 'poweradmin_it_' . getmypid();
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
        @unlink(self::sqliteFile());
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql'], 'sqlite' => ['sqlite']];
    }

    private static function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if ($engine === 'mysql') {
            return new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . self::scratch(), 'root', 'uberuser', $options);
        }
        if ($engine === 'pgsql') {
            $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
            $db->exec('SET search_path TO ' . self::scratch());

            return $db;
        }
        $db = new PDO('sqlite:' . self::sqliteFile(), null, null, $options);
        // As PDODatabaseConnection sets it for the application
        $db->exec('PRAGMA busy_timeout = 5000');

        return $db;
    }

    /** An API-mode install with three zones, with or without the queue row. */
    private function createScratch(string $engine, bool $withQueueRow): void
    {
        try {
            if ($engine === 'mysql') {
                $root = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $root->exec('DROP DATABASE IF EXISTS ' . self::scratch());
                $root->exec('CREATE DATABASE ' . self::scratch());
                $id = 'id INT AUTO_INCREMENT PRIMARY KEY';
            } elseif ($engine === 'pgsql') {
                $admin = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $admin->exec('DROP SCHEMA IF EXISTS ' . self::scratch() . ' CASCADE');
                $admin->exec('CREATE SCHEMA ' . self::scratch());
                $id = 'id SERIAL PRIMARY KEY';
            } else {
                @unlink(self::sqliteFile());
                $id = 'id INTEGER PRIMARY KEY';
            }
            $db = self::connect($engine);
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        $db->exec("CREATE TABLE zones ($id, domain_id INT NULL, owner INT NULL, comment VARCHAR(1024) NULL,
            zone_templ_id INT NOT NULL DEFAULT 0, zone_name VARCHAR(255) NULL, zone_type VARCHAR(8) NULL, zone_master VARCHAR(255) NULL)");
        $db->exec('CREATE UNIQUE INDEX idx_zones_zone_name ON zones (zone_name)');
        $db->exec('CREATE INDEX idx_zones_domain_id ON zones (domain_id)');
        $db->exec("CREATE TABLE zones_groups ($id, domain_id INT NOT NULL, group_id INT NOT NULL)");
        $db->exec("CREATE TABLE api_key_zones ($id, api_key_id INT NOT NULL, zone_id INT NOT NULL)");
        $db->exec("CREATE TABLE app_settings (setting_key VARCHAR(128) NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL, value_type VARCHAR(16) NOT NULL DEFAULT 'string')");

        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 1, 1, 'a.example'), (2, 2, 1, 'b.example'), (3, 3, 1, 'c.example')");
        if ($engine === 'pgsql') {
            $db->exec("SELECT setval('zones_id_seq', 3)");
        }
        if ($withQueueRow) {
            $db->exec("INSERT INTO app_settings (setting_key, setting_value, value_type) VALUES ('backend.zone_ids', 'sql', 'string')");
        }
    }

    /**
     * Runs $work in $count forked processes released at the same instant.
     *
     * @param callable(int): mixed $work Gets the process index; its return value comes back JSON-encoded
     * @return list<array{ok: bool, value: mixed, error: string}>
     */
    private function inProcesses(int $count, callable $work): array
    {
        $go = microtime(true) + 0.3;
        $children = [];
        for ($index = 0; $index < $count; $index++) {
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
                fwrite($pair[1], (string)json_encode($reply));
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

    private function provider(PDO $db): ApiDnsBackendProvider
    {
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('createZoneWithData')->willReturnCallback(fn(array $zone): array => ['name' => $zone['name']]);
        $client->method('deleteZone')->willReturn(true);

        return new ApiDnsBackendProvider($client, $db, $this->createMock(ConfigurationInterface::class), new NullLogger());
    }

    /**
     * @return list<string> The failures
     */
    private function createInParallel(string $engine, bool $withQueueRow, int $creators): array
    {
        $failures = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->createScratch($engine, $withQueueRow);
            $replies = $this->inProcesses($creators, function (int $index) use ($engine, $round): int {
                return (int)$this->provider(self::connect($engine))->createZone("p$index-$round.example", 'NATIVE');
            });

            $ids = [];
            foreach ($replies as $reply) {
                if ($reply['ok'] && $reply['value'] > 0) {
                    $ids[] = $reply['value'];
                } else {
                    $failures[] = $reply['error'] ?: 'createZone returned false';
                }
            }
            $this->assertSame($ids, array_values(array_unique($ids)), 'Parallel creators were given the same zone id');
        }

        return $failures;
    }

    #[DataProvider('engines')]
    public function testParallelZoneCreationSucceedsForEveryCreator(string $engine): void
    {
        $failures = $this->createInParallel($engine, true, 2);

        $this->assertSame([], $failures, count($failures) . ' of ' . (self::ROUNDS * 2) . ' parallel creations failed');
    }

    #[DataProvider('engines')]
    public function testCreatorsRacingToAddTheMissingQueueRowAllSucceed(string $engine): void
    {
        // An install from a pre-release 4.5.0 build has no queue row; the creators add it first
        $failures = $this->createInParallel($engine, false, 3);

        $this->assertSame([], $failures, count($failures) . ' of ' . (self::ROUNDS * 3) . ' parallel creations failed');
        $this->assertSame('api', self::connect($engine)->query("SELECT setting_value FROM app_settings WHERE setting_key = 'backend.zone_ids'")->fetchColumn());
    }
}
