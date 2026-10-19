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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Numeric-looking usernames such as '100' and '1e2' compare equal with `!=`, which
 * skipped the duplicate check on rename. Runs in a separate process because
 * verifyPermission() caches the caller's permissions in a static.
 */
class UserManagerUsernameCompareTest extends TestCase
{
    private PDOCommon $db;

    protected function setUp(): void
    {
        $this->db = new PDOCommon('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT, email TEXT, description TEXT, active INTEGER, perm_templ INTEGER, use_ldap INTEGER, auth_method TEXT, password TEXT)");
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER)");
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'user_edit_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'self')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1)");
        $this->db->exec("INSERT INTO users (id, username, email, active, perm_templ, use_ldap, auth_method) VALUES (2, '100', 'a@example.com', 1, 1, 0, 'sql')");
        $this->db->exec("INSERT INTO users (id, username, email, active, perm_templ, use_ldap, auth_method) VALUES (3, '1e2', 'b@example.com', 1, 1, 0, 'sql')");
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid'], $_SESSION['messages']);
        parent::tearDown();
    }

    private function manager(): UserManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null) => $default);

        return new UserManager($this->db, $config);
    }

    #[RunInSeparateProcess]
    public function testEditUserRefusesRenameToANumericallyEqualTakenName(): void
    {
        $_SESSION['userid'] = 2;

        $result = $this->manager()->editUser(2, '1e2', '', 'a@example.com', '1', '', 1, '', false);

        $this->assertFalse($result);
        $this->assertSame('100', $this->db->query('SELECT username FROM users WHERE id = 2')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function testUpdateUserDetailsRefusesRenameToANumericallyEqualTakenName(): void
    {
        $_SESSION['userid'] = 2;

        $result = $this->manager()->updateUserDetails([
            'uid' => 2,
            'username' => '1e2',
            'fullname' => '',
            'email' => 'a@example.com',
            'templ_id' => 1,
            'descr' => '',
            'active' => 'on',
        ]);

        $this->assertFalse($result);
        $this->assertSame('100', $this->db->query('SELECT username FROM users WHERE id = 2')->fetchColumn());
    }
}
