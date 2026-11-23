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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Email MFA codes follow users.email, so an address set by another account switches
 * that account's email MFA off; the owner's own change and other factors are untouched.
 */
class UserManagerEmailMfaResetTest extends TestCase
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
                "CREATE TABLE user_mfa (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, enabled INTEGER NOT NULL,
                    type TEXT NOT NULL, verification_data TEXT, updated_at TEXT)",
                "INSERT INTO perm_items VALUES (1, 'user_is_ueberuser', '')",
                "INSERT INTO perm_templ VALUES (1, 'admin', '', 'user'), (2, 'user', '', 'user')",
                "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1)",
                "INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method)
                    VALUES (1, 'admin', 'x', 'Admin', 'admin@example.com', '', 1, 1, 0, 'sql'),
                           (2, 'bob', 'x', 'Bob', 'bob@example.com', '', 2, 1, 0, 'sql'),
                           (3, 'eve', 'x', 'Eve', 'eve@example.com', '', 2, 1, 0, 'sql')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $this->db->exec("INSERT INTO user_mfa (user_id, enabled, type, verification_data) VALUES
            (1, 1, 'email', 'pending'), (2, 1, 'email', 'pending'), (3, 1, 'app', NULL)");
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid'], $_SESSION['messages']);
        parent::tearDown();
    }

    private function mfa(int $userId): array
    {
        return $this->db->query("SELECT enabled, verification_data FROM user_mfa WHERE user_id = {$userId}")->fetch(PDO::FETCH_ASSOC);
    }

    private function manager(): UserManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn (string $group, string $key, mixed $default = null): mixed => $default);

        return new UserManager($this->db, $config);
    }

    public function testEditUserByAnotherAccountDisablesEmailMfaWhenTheAddressChanges(): void
    {
        $this->assertTrue($this->manager()->editUser(2, 'bob', 'Bob', 'attacker@example.com', '2', '', 1, '', 0));

        $this->assertSame(0, (int)$this->mfa(2)['enabled']);
        $this->assertNull($this->mfa(2)['verification_data']);
    }

    public function testEditUserByAnotherAccountKeepsEmailMfaWhenTheAddressIsUnchanged(): void
    {
        $this->assertTrue($this->manager()->editUser(2, 'bob', 'Bobby', 'BOB@example.com', '2', '', 1, '', 0));

        $this->assertSame(1, (int)$this->mfa(2)['enabled']);
    }

    public function testEditUserByTheOwnerKeepsEmailMfa(): void
    {
        $this->assertTrue($this->manager()->editUser(1, 'admin', 'Admin', 'new-admin@example.com', '1', '', 1, '', 0));

        $this->assertSame(1, (int)$this->mfa(1)['enabled']);
    }

    public function testEditUserLeavesAnAppFactorAlone(): void
    {
        $this->assertTrue($this->manager()->editUser(3, 'eve', 'Eve', 'other@example.com', '2', '', 1, '', 0));

        $this->assertSame(1, (int)$this->mfa(3)['enabled']);
    }

    public function testUpdateUserDetailsByAnotherAccountDisablesEmailMfa(): void
    {
        $this->assertTrue($this->manager()->updateUserDetails([
            'uid' => 2, 'username' => 'bob', 'fullname' => 'Bob', 'email' => 'attacker@example.com',
            'active' => 'on', 'templ_id' => 2,
        ]));

        $this->assertSame(0, (int)$this->mfa(2)['enabled']);
    }

    public function testUpdateUserDetailsByTheOwnerKeepsEmailMfa(): void
    {
        $this->assertTrue($this->manager()->updateUserDetails([
            'uid' => 1, 'username' => 'admin', 'fullname' => 'Admin', 'email' => 'new-admin@example.com',
            'active' => 'on', 'templ_id' => 1,
        ]));

        $this->assertSame(1, (int)$this->mfa(1)['enabled']);
    }

    public function testHelperReportsWhetherAnEnrolmentWasDisabled(): void
    {
        $this->assertTrue(UserManager::resetEmailMfaAfterForeignEmailChange($this->db, 2, 'bob@example.com', 'x@example.com', 'admin'));
        $this->assertFalse(UserManager::resetEmailMfaAfterForeignEmailChange($this->db, 2, 'bob@example.com', 'y@example.com', 'admin'));
        $this->assertFalse(UserManager::resetEmailMfaAfterForeignEmailChange($this->db, 3, 'eve@example.com', 'y@example.com', 'admin'));
    }
}
