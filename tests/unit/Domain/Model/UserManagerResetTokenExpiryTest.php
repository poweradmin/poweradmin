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
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * A password set through the web edit forms spends the user's outstanding reset links.
 */
class UserManagerResetTokenExpiryTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $_SESSION[SessionKeys::USERID] = 1;

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, password TEXT, fullname TEXT, email TEXT,
            description TEXT, perm_templ INTEGER, perm_templ_source TEXT, active INTEGER, use_ldap INTEGER, auth_method TEXT)");
        $this->db->exec("CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method)
            VALUES (1, 'admin', 'x', 'Admin', 'admin@example.com', '', 1, 1, 0, 'sql'), (2, 'bob', 'x', 'Bob', 'bob@example.com', '', 2, 1, 0, 'sql')");
        $this->db->exec("INSERT INTO password_reset_tokens (email) VALUES ('bob@example.com')");
    }

    protected function tearDown(): void
    {
        unset($_SESSION[SessionKeys::USERID], $_SESSION['messages']);
        parent::tearDown();
    }

    public function testEditUserWithAPasswordSpendsResetTokens(): void
    {
        $this->assertTrue($this->manager()->editUser(2, 'bob', 'Bob', 'bob@example.com', '2', '', 1, 'new-password-1', 0));

        $this->assertSame(0, $this->unused());
    }

    public function testEditUserWithoutAPasswordLeavesResetTokens(): void
    {
        $this->assertTrue($this->manager()->editUser(2, 'bob', 'Bob', 'bob@example.com', '2', '', 1, '', 0));

        $this->assertSame(1, $this->unused());
    }

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
        return (int)$this->db->query("SELECT COUNT(*) FROM password_reset_tokens WHERE used = 0")->fetchColumn();
    }

    private function manager(): UserManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed => $default
        );

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('hasPermission')->willReturn(true);
        $apiPermissionService = $this->createMock(ApiPermissionService::class);
        $apiPermissionService->method('checkPermissionTemplateAssignment')->willReturn(null);

        $manager = new UserManager($this->db, $config);
        foreach (['permissionService' => $permissionService, 'apiPermissionService' => $apiPermissionService] as $name => $value) {
            (new \ReflectionProperty($manager, $name))->setValue($manager, $value);
        }

        return $manager;
    }
}
