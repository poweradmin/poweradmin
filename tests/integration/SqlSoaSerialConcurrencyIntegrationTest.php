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
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Records added to one zone by two writers at once, each adding its row and bumping the
 * serial in one transaction as an API record create does. The writers run as separate
 * processes against a scratch database, because a lock conflict needs two connections.
 *
 * Needs pcntl and the devcontainer (MariaDB, PostgreSQL); skipped otherwise.
 */
#[Group('concurrency')]
class SqlSoaSerialConcurrencyIntegrationTest extends TestCase
{
    // Per-process names, so parallel suite runs do not drop each other's scratch data
    private static string $scratch = '';
    private const WRITES = 25;
    private const ZONE_ID = 1;
    private const SOA = 'ns1.example.com hostmaster.example.com %d 28800 7200 604800 86400';

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available');
        }
        self::$scratch = 'poweradmin_it_soa_serial_' . getmypid();
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::$scratch);
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::$scratch . ' CASCADE');
        } catch (PDOException) {
        }
    }

    private static function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if ($engine === 'mysql') {
            return new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . self::$scratch, 'root', 'uberuser', $options);
        }
        $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
        $db->exec('SET search_path TO ' . self::$scratch);

        return $db;
    }

    private function createScratch(string $engine): void
    {
        try {
            if ($engine === 'mysql') {
                $root = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $root->exec('DROP DATABASE IF EXISTS ' . self::$scratch);
                $root->exec('CREATE DATABASE ' . self::$scratch);
                $root->exec('CREATE TABLE ' . self::$scratch . '.records LIKE pdns.records');
                $db = self::connect('mysql');
            } else {
                $admin = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $admin->exec('DROP SCHEMA IF EXISTS ' . self::$scratch . ' CASCADE');
                $admin->exec('CREATE SCHEMA ' . self::$scratch);
                $db = self::connect('pgsql');
                $db->exec("CREATE TABLE records (id BIGSERIAL PRIMARY KEY, domain_id INT NULL, name VARCHAR(255) NULL, type VARCHAR(10) NULL,
                    content VARCHAR(65535) NULL, ttl INT NULL, prio INT NULL, disabled BOOL NULL DEFAULT 'f', ordername VARCHAR(255) NULL, auth BOOL NULL DEFAULT 't')");
                $db->exec('CREATE INDEX rec_domain_id ON records (domain_id)');
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        $db->exec(sprintf("INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES (%d, 'example.com', 'SOA', '%s', 86400, 0)", self::ZONE_ID, sprintf(self::SOA, 1000)));
    }

    private function manager(PDO $db): SOARecordManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn(null);

        return new SOARecordManager($db, $config);
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

    #[DataProvider('engines')]
    public function testWritersAddingRecordsToOneZoneNeitherDeadlockNorLoseABump(string $engine): void
    {
        $this->createScratch($engine);

        $replies = $this->inTwoProcesses(function (int $index) use ($engine): int {
            $db = self::connect($engine);
            $manager = $this->manager($db);
            $insert = $db->prepare('INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, 300, 0)');
            for ($write = 0; $write < self::WRITES; $write++) {
                // As the API record create does: the row and the serial bump in one transaction
                $db->beginTransaction();
                $insert->execute([self::ZONE_ID, "w$index-$write.example.com", 'A', "192.0.2.$write"]);
                $manager->updateSOASerial(self::ZONE_ID);
                $db->commit();
            }

            return $write;
        });

        $this->assertSame([], array_values(array_filter(array_column($replies, 'error'))));
        $db = self::connect($engine);
        $this->assertSame(sprintf(self::SOA, 1000 + 2 * self::WRITES), $this->manager($db)->getSOARecord(self::ZONE_ID));
        $this->assertSame(2 * self::WRITES, (int)$db->query("SELECT COUNT(*) FROM records WHERE type = 'A'")->fetchColumn());
    }
}
