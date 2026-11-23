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
 * Email MFA codes follow users.email, so an address set by another account switches
 * that account's email MFA off; the owner's own change and other factors are untouched.
 */
class UserManagerEmailMfaResetTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $_SESSION[SessionKeys::USERID] = 1;

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password TEXT, fullname TEXT,
            email TEXT, description TEXT, perm_templ INTEGER, perm_templ_source TEXT, active INTEGER, use_ldap INTEGER, auth_method TEXT)");
        $this->db->exec("CREATE TABLE user_mfa (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, enabled INTEGER NOT NULL,
            type TEXT NOT NULL, verification_data TEXT, updated_at TEXT)");
        $this->db->exec("INSERT INTO users (id, username, fullname, email, description, perm_templ, active, use_ldap, auth_method) VALUES
            (1, 'admin', 'Admin', 'admin@example.com', '', 1, 1, 0, 'sql'),
            (2, 'bob', 'Bob', 'bob@example.com', '', 2, 1, 0, 'sql'),
            (3, 'eve', 'Eve', 'eve@example.com', '', 2, 1, 0, 'sql')");
        $this->db->exec("INSERT INTO user_mfa (user_id, enabled, type, verification_data) VALUES
            (1, 1, 'email', 'pending'), (2, 1, 'email', 'pending'), (3, 1, 'app', NULL)");
    }

    protected function tearDown(): void
    {
        unset($_SESSION[SessionKeys::USERID], $_SESSION['messages']);
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
