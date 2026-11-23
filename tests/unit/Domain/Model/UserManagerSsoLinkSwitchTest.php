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
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * Moving an account to LDAP sign-in drops the OIDC and SAML identities linked to it.
 */
class UserManagerSsoLinkSwitchTest extends TestCase
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
                "CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, provider_id TEXT NOT NULL, oidc_subject TEXT NOT NULL)",
                "CREATE TABLE saml_user_links (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, provider_id TEXT NOT NULL, saml_subject TEXT NOT NULL)",
                "INSERT INTO perm_items VALUES (1, 'user_is_ueberuser', '')",
                "INSERT INTO perm_templ VALUES (1, 'admin', '', 'user'), (2, 'user', '', 'user')",
                "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1)",
                "INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method)
                    VALUES (1, 'admin', 'x', 'Admin', 'admin@example.com', '', 1, 1, 0, 'sql'),
                           (2, 'bob', 'x', 'Bob', 'bob@example.com', '', 2, 1, 0, 'sql'),
                           (3, 'eve', 'x', 'Eve', 'eve@example.com', '', 2, 1, 0, 'oidc'),
                           (4, 'lia', 'x', 'Lia', 'lia@example.com', '', 2, 1, 1, 'ldap')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        foreach ([2, 3, 4] as $id) {
            $this->db->exec("INSERT INTO oidc_user_links (user_id, provider_id, oidc_subject) VALUES ({$id}, 'okta', 'sub-{$id}')");
            $this->db->exec("INSERT INTO saml_user_links (user_id, provider_id, saml_subject) VALUES ({$id}, 'adfs', 'name-{$id}')");
        }
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid'], $_SESSION['messages']);
        parent::tearDown();
    }

    private function links(int $id): int
    {
        return (int)$this->db->query("SELECT (SELECT COUNT(*) FROM oidc_user_links WHERE user_id = {$id})
            + (SELECT COUNT(*) FROM saml_user_links WHERE user_id = {$id})")->fetchColumn();
    }

    private function repository(): DbUserRepository
    {
        return new DbUserRepository($this->db, $this->createMock(ConfigurationManager::class));
    }

    private function manager(): UserManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn (string $group, string $key, mixed $default = null): mixed => $default);

        return new UserManager($this->db, $config);
    }

    public function testRepositoryDropsLinksWhenAnAccountMovesToLdap(): void
    {
        $this->assertTrue($this->repository()->updateUser(2, ['use_ldap' => 1]));

        $this->assertSame(0, $this->links(2));
        $this->assertSame(2, $this->links(3));
    }

    public function testRepositoryDropsLinksOfAnSsoAccountMovedToLdap(): void
    {
        $this->assertTrue($this->repository()->updateUser(3, ['use_ldap' => 1]));

        $this->assertSame(0, $this->links(3));
    }

    public function testRepositoryKeepsLinksOfAnAccountThatStaysOnSqlOrSso(): void
    {
        $this->assertTrue($this->repository()->updateUser(2, ['use_ldap' => 0, 'fullname' => 'Bobby']));
        $this->assertTrue($this->repository()->updateUser(3, ['use_ldap' => 0]));

        $this->assertSame(2, $this->links(2));
        $this->assertSame(2, $this->links(3));
    }

    public function testRepositoryKeepsLinksOnAReSaveOfAnLdapAccount(): void
    {
        $this->assertTrue($this->repository()->updateUser(4, ['use_ldap' => 1, 'fullname' => 'Lia L']));

        $this->assertSame(2, $this->links(4));
    }

    #[RunInSeparateProcess]
    public function testEditUserDropsLinksWhenAnAccountMovesToLdap(): void
    {
        $this->assertTrue($this->manager()->editUser(2, 'bob', 'Bob', 'bob@example.com', '2', '', 1, '', 1));

        $this->assertSame(0, $this->links(2));
    }

    #[RunInSeparateProcess]
    public function testEditUserKeepsLinksOfASqlAccount(): void
    {
        $this->assertTrue($this->manager()->editUser(2, 'bob', 'Bob', 'bob@example.com', '2', '', 1, '', 0));

        $this->assertSame(2, $this->links(2));
    }

    #[RunInSeparateProcess]
    public function testUpdateUserDetailsDropsLinksWhenAnAccountMovesToLdap(): void
    {
        $this->assertTrue($this->manager()->updateUserDetails([
            'uid' => 2, 'username' => 'bob', 'fullname' => 'Bob', 'email' => 'bob@example.com',
            'active' => 'on', 'templ_id' => 2, 'use_ldap' => '1',
        ]));

        $this->assertSame(0, $this->links(2));
    }

    #[RunInSeparateProcess]
    public function testUpdateUserDetailsKeepsLinksOfASqlAccount(): void
    {
        $this->assertTrue($this->manager()->updateUserDetails([
            'uid' => 2, 'username' => 'bob', 'fullname' => 'Bob', 'email' => 'bob@example.com',
            'active' => 'on', 'templ_id' => 2,
        ]));

        $this->assertSame(2, $this->links(2));
    }
}
