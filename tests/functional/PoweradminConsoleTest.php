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

declare(strict_types=1);

namespace Poweradmin\Tests\Functional;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs bin/poweradmin as a subprocess against a throwaway SQLite database
 * built from the shipped schemas, so the console path is exercised end to
 * end without HTTP, a session or the devcontainer.
 */
class PoweradminConsoleTest extends TestCase
{
    private const ADMIN_TEMPLATE = 1;
    private const VIEWER_TEMPLATE = 4;
    private const GUEST_TEMPLATE = 5;

    private static string $repositoryRoot;
    private static string $fixtureDir;
    private static string $settingsPath;

    public static function setUpBeforeClass(): void
    {
        self::$repositoryRoot = dirname(__DIR__, 2);
        self::$fixtureDir = sys_get_temp_dir() . '/poweradmin-console-' . bin2hex(random_bytes(4));
        mkdir(self::$fixtureDir);

        $dbFile = self::$fixtureDir . '/poweradmin.sqlite';
        self::seedDatabase($dbFile);

        self::$settingsPath = self::$fixtureDir . '/settings.php';
        file_put_contents(
            self::$settingsPath,
            "<?php\nreturn ['database' => ['type' => 'sqlite', 'file' => " . var_export($dbFile, true) . "]];\n"
        );
    }

    public static function tearDownAfterClass(): void
    {
        foreach ((array) glob(self::$fixtureDir . '/*') as $file) {
            unlink($file);
        }
        rmdir(self::$fixtureDir);
    }

    public function testUeberuserSeesEveryZoneWithRecordCounts(): void
    {
        [$exit, $stdout, $stderr] = $this->runConsole(['--as-user=1', 'zone:list']);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("ID\tNAME\tTYPE\tRECORDS\n10\texample.com\tMASTER\t2\n11\texample.net\tNATIVE\t1\n", $stdout);
        $this->assertSame('', $stderr);
    }

    public function testViewerSeesOnlyOwnedZonesWithTheCommandLevelOption(): void
    {
        [$exit, $stdout] = $this->runConsole(['zone:list', '--user=2']);

        $this->assertSame(0, $exit);
        $this->assertSame("ID\tNAME\tTYPE\tRECORDS\n10\texample.com\tMASTER\t2\n", $stdout);
    }

    public function testGuestAndSystemActorSeeOnlyTheHeader(): void
    {
        foreach ([['zone:list'], ['--as-user=3', 'zone:list']] as $argv) {
            [$exit, $stdout, $stderr] = $this->runConsole($argv);

            $this->assertSame(0, $exit);
            $this->assertSame("ID\tNAME\tTYPE\tRECORDS\n", $stdout);
            $this->assertStringContainsString('may not view any zones', $stderr);
        }
    }

    public function testUsageErrorsExitWithOne(): void
    {
        $cases = [
            'no command' => [[], 'No command given'],
            'unknown command' => [['zone:delete'], 'Unknown command "zone:delete"'],
            'unknown option' => [['zone:list', '--verbose'], 'Unknown option "--verbose"'],
            'non-numeric user' => [['--as-user=root', 'zone:list'], 'expects a positive integer'],
            'stray argument' => [['zone:list', 'example.com'], 'takes no arguments'],
            'missing user' => [['--as-user=99', 'zone:list'], 'User 99 does not exist'],
        ];

        foreach ($cases as $label => [$argv, $message]) {
            [$exit, $stdout, $stderr] = $this->runConsole($argv);

            $this->assertSame(1, $exit, $label);
            $this->assertStringContainsString($message, $stderr, $label);
            $this->assertStringContainsString('Usage: bin/poweradmin', $stderr, $label);
            $this->assertSame('', $stdout, $label);
        }
    }

    public function testHelpPrintsUsageAndExitsZero(): void
    {
        [$exit, $stdout] = $this->runConsole(['--help']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('zone:list', $stdout);
    }

    public function testUnreachableDatabaseExitsWithTwo(): void
    {
        $settings = self::$fixtureDir . '/broken-settings.php';
        file_put_contents($settings, "<?php\nreturn ['database' => ['type' => 'sqlite', 'file' => '/nonexistent/poweradmin.sqlite']];\n");

        [$exit, $stdout, $stderr] = $this->runConsole(['zone:list'], $settings);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Database connection failed', $stderr);
        $this->assertSame('', $stdout);
    }

    public function testAQueryFailureAfterBootExitsWithTwo(): void
    {
        $dbFile = self::$fixtureDir . '/empty.sqlite';
        touch($dbFile);
        $settings = self::$fixtureDir . '/empty-settings.php';
        file_put_contents($settings, "<?php\nreturn ['database' => ['type' => 'sqlite', 'file' => " . var_export($dbFile, true) . "]];\n");

        [$exit, $stdout, $stderr] = $this->runConsole(['--as-user=1', 'zone:list'], $settings);

        $this->assertSame(2, $exit);
        $this->assertStringStartsWith('Error: ', $stderr);
        $this->assertStringNotContainsString('Stack trace', $stderr);
        $this->assertSame('', $stdout);
    }

    /**
     * @param list<string> $argv
     * @return array{int, string, string}
     */
    private function runConsole(array $argv, ?string $settingsPath = null): array
    {
        $process = proc_open(
            [PHP_BINARY, self::$repositoryRoot . '/bin/poweradmin', ...$argv],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::$repositoryRoot,
            ['PATH' => getenv('PATH'), 'PA_CONFIG_PATH' => $settingsPath ?? self::$settingsPath]
        );
        $this->assertIsResource($process, 'Failed to start the console subprocess');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * Two zones: example.com owned by the viewer (two records), example.net
     * owned by the admin (one record). The guest owns nothing and has no permissions.
     */
    private static function seedDatabase(string $dbFile): void
    {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec((string) file_get_contents(self::$repositoryRoot . '/sql/pdns/51/schema.sqlite3.sql'));
        $pdo->exec((string) file_get_contents(self::$repositoryRoot . '/sql/poweradmin-sqlite-db-structure.sql'));

        $users = $pdo->prepare('INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0)');
        $users->execute([1, 'admin', 'x', 'Admin', 'admin@example.com', '', self::ADMIN_TEMPLATE]);
        $users->execute([2, 'viewer', 'x', 'Viewer', 'viewer@example.com', '', self::VIEWER_TEMPLATE]);
        $users->execute([3, 'guest', 'x', 'Guest', 'guest@example.com', '', self::GUEST_TEMPLATE]);

        $pdo->exec("INSERT INTO domains (id, name, type) VALUES (10, 'example.com', 'MASTER'), (11, 'example.net', 'NATIVE')");
        $records = $pdo->prepare('INSERT INTO records (domain_id, name, type, content, ttl) VALUES (?, ?, ?, ?, 3600)');
        $records->execute([10, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 1 10800 3600 604800 3600']);
        $records->execute([10, 'www.example.com', 'A', '192.0.2.1']);
        $records->execute([11, 'example.net', 'SOA', 'ns1.example.net hostmaster.example.net 1 10800 3600 604800 3600']);

        $pdo->exec('INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (10, 2, 0), (11, 1, 0)');
    }
}
