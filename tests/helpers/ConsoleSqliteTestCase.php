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

namespace TestHelpers;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs bin/poweradmin as a subprocess against a throwaway SQLite database
 * built from the shipped schemas, so the console path is exercised end to
 * end without HTTP, a session or the devcontainer.
 *
 * The fixture holds two zones: example.com (id 10, MASTER, two records) owned
 * by the viewer (user 2) and example.net (id 11, NATIVE, one record) owned by
 * the admin (user 1). The guest (user 3) owns nothing and has no permissions.
 */
abstract class ConsoleSqliteTestCase extends TestCase
{
    protected const ADMIN_USER = 1;
    protected const VIEWER_USER = 2;
    protected const GUEST_USER = 3;

    private const ADMIN_TEMPLATE = 1;
    private const VIEWER_TEMPLATE = 4;
    private const GUEST_TEMPLATE = 5;

    protected static string $repositoryRoot;
    protected static string $fixtureDir;
    protected static string $settingsPath;

    public static function setUpBeforeClass(): void
    {
        self::$repositoryRoot = dirname(__DIR__, 2);
        self::$fixtureDir = sys_get_temp_dir() . '/poweradmin-console-' . bin2hex(random_bytes(4));
        mkdir(self::$fixtureDir);

        $dbFile = self::$fixtureDir . '/poweradmin.sqlite';
        self::seedDatabase($dbFile);
        self::$settingsPath = self::writeSettings('settings.php', $dbFile);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ((array) glob(self::$fixtureDir . '/*') as $file) {
            unlink($file);
        }
        rmdir(self::$fixtureDir);
    }

    /** Writes a settings file in the fixture directory pointing at the given SQLite file */
    protected static function writeSettings(string $fileName, string $dbFile): string
    {
        $path = self::$fixtureDir . '/' . $fileName;
        file_put_contents(
            $path,
            "<?php\nreturn ['database' => ['type' => 'sqlite', 'file' => " . var_export($dbFile, true) . "]];\n"
        );

        return $path;
    }

    /**
     * @param list<string> $argv
     * @return array{int, string, string} exit code, stdout, stderr
     */
    protected function runConsole(array $argv, ?string $settingsPath = null): array
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

    private static function seedDatabase(string $dbFile): void
    {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec((string) file_get_contents(self::$repositoryRoot . '/sql/pdns/51/schema.sqlite3.sql'));
        $pdo->exec((string) file_get_contents(self::$repositoryRoot . '/sql/poweradmin-sqlite-db-structure.sql'));

        $users = $pdo->prepare('INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0)');
        $users->execute([self::ADMIN_USER, 'admin', 'x', 'Admin', 'admin@example.com', '', self::ADMIN_TEMPLATE]);
        $users->execute([self::VIEWER_USER, 'viewer', 'x', 'Viewer', 'viewer@example.com', '', self::VIEWER_TEMPLATE]);
        $users->execute([self::GUEST_USER, 'guest', 'x', 'Guest', 'guest@example.com', '', self::GUEST_TEMPLATE]);

        $pdo->exec("INSERT INTO domains (id, name, type) VALUES (10, 'example.com', 'MASTER'), (11, 'example.net', 'NATIVE')");
        $records = $pdo->prepare('INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (?, ?, ?, ?, ?, 3600, ?, ?)');
        $records->execute([100, 10, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 1 10800 3600 604800 3600', 0, 0]);
        $records->execute([101, 10, 'www.example.com', 'A', '192.0.2.1', 0, 1]);
        $records->execute([102, 11, 'example.net', 'SOA', 'ns1.example.net hostmaster.example.net 1 10800 3600 604800 3600', 0, 0]);

        $pdo->exec('INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (10, 2, 0), (11, 1, 0)');
    }
}
