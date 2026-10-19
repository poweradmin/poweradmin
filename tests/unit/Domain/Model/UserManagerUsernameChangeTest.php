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
 * Numeric-looking usernames such as '100' and '1e2' are equal under a loose
 * comparison, so a rename between them skipped the "already exists" check.
 */
class UserManagerUsernameChangeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $_SESSION[SessionKeys::USERID] = 1;

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            password TEXT,
            fullname TEXT,
            email TEXT,
            description TEXT,
            perm_templ INTEGER,
            perm_templ_source TEXT,
            active INTEGER,
            use_ldap INTEGER,
            auth_method TEXT
        )");
        $this->db->exec("INSERT INTO users (id, username, fullname, email, description, perm_templ, active, use_ldap, auth_method)
            VALUES (1, 'admin', 'Admin', 'admin@example.com', '', 1, 1, 0, 'sql')");
        $this->db->exec("INSERT INTO users (id, username, fullname, email, description, perm_templ, active, use_ldap, auth_method)
            VALUES (2, '100', 'One', 'one@example.com', '', 2, 1, 0, 'sql')");
        $this->db->exec("INSERT INTO users (id, username, fullname, email, description, perm_templ, active, use_ldap, auth_method)
            VALUES (3, '1e2', 'Two', 'two@example.com', '', 2, 1, 0, 'sql')");
    }

    protected function tearDown(): void
    {
        unset($_SESSION[SessionKeys::USERID], $_SESSION['messages']);
        parent::tearDown();
    }

    public function testEditUserRefusesRenamingOntoANumericallyEqualUsername(): void
    {
        $result = $this->manager()->editUser(3, '100', 'Two', 'two@example.com', '2', '', 1, '', 0);

        $this->assertFalse($result);
        $this->assertSame('1e2', $this->storedUsername(3));
    }

    public function testUpdateUserDetailsRefusesRenamingOntoANumericallyEqualUsername(): void
    {
        $result = $this->manager()->updateUserDetails([
            'uid' => 3,
            'username' => '100',
            'fullname' => 'Two',
            'email' => 'two@example.com',
            'active' => 'on',
            'templ_id' => 2,
        ]);

        $this->assertFalse($result);
        $this->assertSame('1e2', $this->storedUsername(3));
    }

    private function manager(): UserManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed => $default
        );

        // The caller passes every permission check, so only the username check decides.
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('hasPermission')->willReturn(true);
        $apiPermissionService = $this->createMock(ApiPermissionService::class);
        $apiPermissionService->method('checkPermissionTemplateAssignment')->willReturn(null);

        $manager = new UserManager($this->db, $config);
        $this->setProperty($manager, 'permissionService', $permissionService);
        $this->setProperty($manager, 'apiPermissionService', $apiPermissionService);

        return $manager;
    }

    private function storedUsername(int $id): string
    {
        return (string)$this->db->query('SELECT username FROM users WHERE id = ' . $id)->fetchColumn();
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
