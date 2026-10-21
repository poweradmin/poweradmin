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
use Poweradmin\Application\Service\Auth\UserAuthenticationService;
use Poweradmin\Application\Service\User\PasswordPolicyService;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\User\UserProfileAssembler;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use TestHelpers\FakeConfiguration;

/**
 * Two super admins disabling, deleting or demoting each other at the same moment must
 * leave one active super admin. The writers run as separate processes against a scratch
 * database, because a lock conflict needs two connections.
 *
 * Needs pcntl; MariaDB and PostgreSQL come from the devcontainer and are skipped without it.
 */
#[Group('concurrency')]
class LastSuperAdminConcurrencyIntegrationTest extends TestCase
{
    // Per-process names, so parallel suite runs do not drop each other's scratch data
    private static string $scratch = '';
    private const ROUNDS = 8;
    private const ADMIN_TEMPL = 1;
    private const GUEST_TEMPL = 5;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available');
        }
        self::$scratch = 'poweradmin_it_last_admin_' . getmypid();
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
        @unlink(self::sqlitePath());
    }

    private static function sqlitePath(): string
    {
        return sys_get_temp_dir() . '/' . self::$scratch . '.sqlite';
    }

    private static function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if ($engine === 'mysql') {
            return new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . self::$scratch, 'root', 'uberuser', $options);
        }
        if ($engine === 'sqlite') {
            return new PDO('sqlite:' . self::sqlitePath(), null, null, $options + [PDO::ATTR_TIMEOUT => 20]);
        }
        $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
        $db->exec('SET search_path TO ' . self::$scratch);

        return $db;
    }

    /** A scratch install with two active super admins (users 1 and 2) and a template without the permission. */
    private function createScratch(string $engine): void
    {
        try {
            if ($engine === 'mysql') {
                $root = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $root->exec('DROP DATABASE IF EXISTS ' . self::$scratch);
                $root->exec('CREATE DATABASE ' . self::$scratch);
                foreach ($root->query('SHOW TABLES FROM poweradmin')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                    $root->exec('CREATE TABLE ' . self::$scratch . ".$table LIKE poweradmin.$table");
                }
                $db = self::connect('mysql');
                // Only the columns the guards read matter; the other NOT NULL user columns may default
                $db->exec("SET SESSION sql_mode = ''");
            } elseif ($engine === 'pgsql') {
                $admin = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $admin->exec('DROP SCHEMA IF EXISTS ' . self::$scratch . ' CASCADE');
                $admin->exec('CREATE SCHEMA ' . self::$scratch);
                foreach ($admin->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                    $admin->exec('CREATE TABLE ' . self::$scratch . ".$table (LIKE public.$table INCLUDING DEFAULTS INCLUDING INDEXES)");
                }
                $db = self::connect('pgsql');
            } else {
                @unlink(self::sqlitePath());
                $db = self::connect('sqlite');
                $db->exec((string)file_get_contents(dirname(__DIR__, 2) . '/sql/poweradmin-sqlite-db-structure.sql'));
                $db->exec('DELETE FROM users');
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        if ($engine !== 'sqlite') {
            $db->exec("INSERT INTO perm_items (id, name, descr) VALUES (53, 'user_is_ueberuser', 'Super admin')");
            $db->exec("INSERT INTO perm_templ (id, name, descr, template_type) VALUES (" . self::ADMIN_TEMPL . ", 'Administrator', '', 'user'), (" . self::GUEST_TEMPL . ", 'Guest', '', 'user')");
            $db->exec("INSERT INTO perm_templ_items (id, templ_id, perm_id) VALUES (1, " . self::ADMIN_TEMPL . ", 53)");
        }
        $this->seedAdmins($db);
    }

    private function seedAdmins(PDO $db): void
    {
        $db->exec('DELETE FROM users');
        foreach ([1, 2] as $userId) {
            $db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES ($userId, 'admin$userId', 'x', 'Admin $userId', 'a$userId@example.com', '', " . self::ADMIN_TEMPL . ", 1, 0)");
        }
    }

    /** The service over a repository that stays a moment between its check and the write, to open the race window. */
    private function service(string $engine): UserManagementService
    {
        $config = new FakeConfiguration(['database' => ['type' => $engine, 'pdns_db_name' => '']]);
        $db = self::connect($engine);
        $repository = new class ($db, $config, false) extends DbUserRepository {
            public function isLastUberuser(int $userId): bool
            {
                $last = parent::isLastUberuser($userId);
                usleep(150000);

                return $last;
            }
        };
        $permissions = new PermissionService($repository);

        return new UserManagementService(
            $repository,
            $permissions,
            new UserProfileAssembler($permissions, $this->createMock(UserGroupRepositoryInterface::class)),
            new UserAuthenticationService(),
            new PasswordPolicyService($config),
            false,
            $this->createMock(DomainManagerInterface::class),
            $this->createMock(ZoneManagementService::class),
            transaction: new PdoTransaction($db)
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
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql'], 'sqlite' => ['sqlite']];
    }

    /**
     * Each process acts on the other's account, so both would pass an unlocked check.
     *
     * @param callable(UserManagementService, int): array $act Gets the service and the other admin's id
     */
    private function assertOneAdminSurvives(string $engine, callable $act): void
    {
        $this->createScratch($engine);
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->seedAdmins(self::connect($engine));
            $replies = $this->inTwoProcesses(function (int $index) use ($engine, $act): array {
                $result = $act($this->service($engine), $index === 0 ? 2 : 1);

                return ['success' => $result['success'], 'refusal' => isset($result['refusal']) ? $result['refusal']->name : ''];
            });

            $this->assertSame([], array_values(array_filter(array_column($replies, 'error'))), 'A writer failed');
            $outcomes = array_map(static fn(array $reply): bool => $reply['value']['success'], $replies);
            sort($outcomes);
            $this->assertSame([false, true], $outcomes, 'Exactly one of the two may succeed: ' . json_encode($replies));
            $refused = array_values(array_filter($replies, static fn(array $reply): bool => !$reply['value']['success']))[0];
            $this->assertSame('CONFLICT', $refused['value']['refusal']);
            $left = self::connect($engine)->query(
                "SELECT COUNT(*) FROM users WHERE active = 1 AND perm_templ = " . self::ADMIN_TEMPL
            )->fetchColumn();
            $this->assertSame(1, (int)$left, 'One active super admin must remain');
        }
    }

    #[DataProvider('engines')]
    public function testTwoSuperAdminsDisablingEachOtherLeaveOne(string $engine): void
    {
        $this->assertOneAdminSurvives($engine, static fn(UserManagementService $service, int $other): array => $service->updateUser($other, new UpdateUserCommand(active: false)));
    }

    #[DataProvider('engines')]
    public function testTwoSuperAdminsDeletingEachOtherLeaveOne(string $engine): void
    {
        $this->assertOneAdminSurvives($engine, static fn(UserManagementService $service, int $other): array => $service->deleteUser($other));
    }

    #[DataProvider('engines')]
    public function testTwoSuperAdminsDemotingEachOtherLeaveOne(string $engine): void
    {
        $this->assertOneAdminSurvives($engine, fn(UserManagementService $service, int $other): array => $service->assignPermissionTemplate($other, self::GUEST_TEMPL));
    }
}
