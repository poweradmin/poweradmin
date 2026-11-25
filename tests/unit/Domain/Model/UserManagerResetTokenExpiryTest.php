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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * A password set through the web edit forms spends the user's outstanding reset links.
 * Separate processes keep UserManager::verifyPermission()'s static cache out of the way.
 */
class UserManagerResetTokenExpiryTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $_SESSION['userid'] = 1;

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, password TEXT, fullname TEXT, email TEXT,
                    description TEXT, perm_templ INTEGER, perm_templ_source TEXT, active INTEGER, use_ldap INTEGER, auth_method TEXT)",
                "CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT, descr TEXT)",
                "CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT, descr TEXT, template_type TEXT)",
                "CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)",
                "CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)",
                "CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER)",
                "CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)",
                "INSERT INTO perm_items VALUES (1, 'user_is_ueberuser', '')",
                "INSERT INTO perm_templ VALUES (1, 'admin', '', 'user'), (2, 'user', '', 'user')",
                "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1)",
                "INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method)
                    VALUES (1, 'admin', 'x', 'Admin', 'admin@example.com', '', 1, 1, 0, 'sql'),
                           (2, 'bob', 'x', 'Bob', 'bob@example.com', '', 2, 1, 0, 'sql')",
                "INSERT INTO password_reset_tokens (email) VALUES ('bob@example.com')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    #[RunInSeparateProcess]
    public function testEditUserWithAPasswordSpendsResetTokens(): void
    {
        $this->assertTrue($this->manager()->editUser(2, 'bob', 'Bob', 'bob@example.com', '2', '', 1, 'new-password-1', 0));

        $this->assertSame(0, $this->unused());
    }

    #[RunInSeparateProcess]
    public function testEditUserChangingEmailAndPasswordSpendsTokensOfTheOldAddress(): void
    {
        $this->assertTrue($this->manager()->editUser(2, 'bob', 'Bob', 'robert@example.com', '2', '', 1, 'new-password-1', 0));

        $this->assertSame(0, $this->unused());
    }

    #[RunInSeparateProcess]
    public function testUpdateUserDetailsChangingEmailAndPasswordSpendsTokensOfTheOldAddress(): void
    {
        $this->assertTrue($this->manager()->updateUserDetails([
            'uid' => 2, 'username' => 'bob', 'fullname' => 'Bob', 'email' => 'robert@example.com',
            'active' => 'on', 'templ_id' => 2, 'password' => 'new-password-1',
        ]));

        $this->assertSame(0, $this->unused());
    }

    #[RunInSeparateProcess]
    public function testEditUserWithoutAPasswordLeavesResetTokens(): void
    {
        $this->assertTrue($this->manager()->editUser(2, 'bob', 'Bob', 'bob@example.com', '2', '', 1, '', 0));

        $this->assertSame(1, $this->unused());
    }

    #[RunInSeparateProcess]
    public function testUpdateUserDetailsWithAPasswordSpendsResetTokens(): void
    {
        $this->assertTrue($this->manager()->updateUserDetails([
            'uid' => 2, 'username' => 'bob', 'fullname' => 'Bob', 'email' => 'bob@example.com',
            'active' => 'on', 'templ_id' => 2, 'password' => 'new-password-1',
        ]));

        $this->assertSame(0, $this->unused());
    }

    private function unused(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM password_reset_tokens WHERE used = 0")->fetchColumn();
    }

    private function manager(): UserManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);

        return new UserManager($this->db, $config);
    }
}
