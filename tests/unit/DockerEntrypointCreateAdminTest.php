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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Runs create_admin_user() from docker-entrypoint.sh against a SQLite file: it must not add
 * an account when an active superuser already exists, only on a database without one.
 */
class DockerEntrypointCreateAdminTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        foreach (['bash', 'sqlite3'] as $binary) {
            if (trim((string) shell_exec('command -v ' . $binary)) === '') {
                $this->markTestSkipped($binary . ' is not available');
            }
        }

        $this->dbFile = tempnam(sys_get_temp_dir(), 'pa-entrypoint-') ?: '';
        $sql = "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, fullname TEXT, email TEXT, description TEXT, perm_templ INTEGER, active INTEGER, use_ldap INTEGER);
            CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER);
            INSERT INTO perm_items (id, name) VALUES (53, 'user_is_ueberuser'), (54, 'zone_content_view_own');
            INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 53), (2, 53), (3, 54);";
        $this->sqlite($sql);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '') {
            @unlink($this->dbFile);
        }
    }

    private function sqlite(string $sql): string
    {
        return trim((string) shell_exec('sqlite3 ' . escapeshellarg($this->dbFile) . ' ' . escapeshellarg($sql)));
    }

    private function runCreateAdmin(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($script);

        $parts = ["log() { :; }\ndebug_log() { :; }\n"];
        foreach (['to_php_bool', 'escape_sql', 'admin_exists_sql', 'create_admin_user'] as $name) {
            if (preg_match('/^' . $name . '\(\) \{\n.*?\n\}\n/ms', $script, $m) === 1) {
                $parts[] = $m[0];
            }
        }

        $command = 'DB_TYPE=sqlite DB_FILE=' . escapeshellarg($this->dbFile)
            . ' PA_CREATE_ADMIN=true PA_ADMIN_PASSWORD=Secret-pass-1 '
            . 'bash -c ' . escapeshellarg(implode("\n", $parts) . "\ncreate_admin_user") . ' 2>&1';
        exec($command, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function testAdminIsCreatedWhenNoSuperuserExists(): void
    {
        $this->sqlite("INSERT INTO users (username, perm_templ, active) VALUES ('viewer', 3, 1)");

        $this->runCreateAdmin();

        $this->assertSame('1', $this->sqlite("SELECT COUNT(*) FROM users WHERE username = 'admin' AND perm_templ = 1"));
    }

    public function testAdminIsNotCreatedWhenAnotherActiveSuperuserExists(): void
    {
        $this->sqlite("INSERT INTO users (username, perm_templ, active) VALUES ('root', 1, 1)");

        $this->runCreateAdmin();

        $this->assertSame('0', $this->sqlite("SELECT COUNT(*) FROM users WHERE username = 'admin'"));
    }

    public function testASuperuserThroughAnotherTemplateCounts(): void
    {
        $this->sqlite("INSERT INTO users (username, perm_templ, active) VALUES ('ops', 2, 1)");

        $this->runCreateAdmin();

        $this->assertSame('0', $this->sqlite("SELECT COUNT(*) FROM users WHERE username = 'admin'"));
    }

    public function testADisabledSuperuserDoesNotBlockCreation(): void
    {
        $this->sqlite("INSERT INTO users (username, perm_templ, active) VALUES ('old', 1, 0)");

        $this->runCreateAdmin();

        $this->assertSame('1', $this->sqlite("SELECT COUNT(*) FROM users WHERE username = 'admin'"));
    }

    public function testRunningTwiceCreatesOneAdmin(): void
    {
        $this->runCreateAdmin();
        $this->runCreateAdmin();

        $this->assertSame('1', $this->sqlite("SELECT COUNT(*) FROM users WHERE perm_templ = 1"));
    }
}
