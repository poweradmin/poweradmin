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
 * A delegate with user_add_new + user_edit_templ_perm may create an account only on
 * a template within their own permissions, the pre-selected minimal one included.
 * Runs in a separate process because
 * verifyPermission() caches the caller's permissions in a static.
 */
class UserManagerAddUserTemplateTest extends TestCase
{
    private const DELEGATE_TEMPLATE = 1;
    private const MINIMAL_TEMPLATE = 2;
    private const RICHER_TEMPLATE = 3;

    private PDOCommon $db;

    protected function setUp(): void
    {
        $this->db = new PDOCommon('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, fullname TEXT, email TEXT, description TEXT, active INTEGER, perm_templ INTEGER, use_ldap INTEGER, auth_method TEXT)");
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER)");
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'user_add_new'), (2, 'user_edit_templ_perm'), (3, 'zone_content_view_own'), (4, 'user_edit_others')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'delegate'), (2, 'minimal'), (3, 'richer')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1), (1, 2), (2, 3), (3, 4)");
        $this->db->exec("INSERT INTO users (id, username, email, active, perm_templ, use_ldap, auth_method) VALUES (7, 'delegate', 'd@example.com', 1, 1, 0, 'sql')");
        $_SESSION['userid'] = 7;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid'], $_SESSION['messages']);
        parent::tearDown();
    }

    private function addUser(int $templateId): int|false
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null) => $group === 'ldap' && $key === 'enabled' ? true : $default
        );

        return (new UserManager($this->db, $config))->addNewUser([
            'username' => 'newuser',
            'email' => 'new@example.com',
            'perm_templ' => $templateId,
            'use_ldap' => 1,
            'active' => 1,
        ]);
    }

    #[RunInSeparateProcess]
    public function testTemplateWithinTheCallersPermissionsIsAccepted(): void
    {
        $this->assertNotFalse($this->addUser(self::DELEGATE_TEMPLATE));
        $this->assertSame(self::DELEGATE_TEMPLATE, (int)$this->db->query("SELECT perm_templ FROM users WHERE username = 'newuser'")->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function testMinimalTemplateSubmittedExplicitlyIsRefused(): void
    {
        $this->assertFalse($this->addUser(self::MINIMAL_TEMPLATE));
    }

    #[RunInSeparateProcess]
    public function testRicherTemplateIsRefused(): void
    {
        $this->assertFalse($this->addUser(self::RICHER_TEMPLATE));
    }
}
