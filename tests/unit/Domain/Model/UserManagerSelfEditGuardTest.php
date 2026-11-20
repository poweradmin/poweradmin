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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * The users list save keeps the own row's username and active flag when the
 * caller lacks user_edit_others, like the single edit form (#1327).
 */
class UserManagerSelfEditGuardTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $_SESSION[SessionKeys::USERID] = 2;

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
            VALUES (2, 'alice', 'Alice', 'alice@example.com', '', 2, 1, 0, 'sql')");
    }

    protected function tearDown(): void
    {
        unset($_SESSION[SessionKeys::USERID], $_SESSION['messages']);
        parent::tearDown();
    }

    #[Test]
    public function testOwnRowKeepsStoredUsernameAndActiveWithoutEditOthers(): void
    {
        $result = $this->manager(['user_edit_own'])->updateUserDetails($this->row('renamed', null));

        $this->assertTrue($result);
        $this->assertSame(['alice', 1, 'New Name'], $this->stored());
    }

    #[Test]
    public function testOwnRowCanBeRenamedWithEditOthers(): void
    {
        $result = $this->manager(['user_edit_own', 'user_edit_others'])->updateUserDetails($this->row('renamed', null));

        $this->assertTrue($result);
        $this->assertSame(['renamed', 0, 'New Name'], $this->stored());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $username, ?string $active): array
    {
        $row = ['uid' => 2, 'username' => $username, 'fullname' => 'New Name', 'email' => 'alice@example.com', 'templ_id' => 2];
        if ($active !== null) {
            $row['active'] = $active;
        }

        return $row;
    }

    /**
     * @param list<string> $granted
     */
    private function manager(array $granted): UserManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed => $default
        );

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('hasPermission')->willReturnCallback(
            fn (int $userId, string $permission): bool => in_array($permission, $granted, true)
        );

        $manager = new UserManager($this->db, $config);
        $property = new \ReflectionProperty($manager, 'permissionService');
        $property->setValue($manager, $permissionService);

        return $manager;
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function stored(): array
    {
        $row = $this->db->query('SELECT username, active, fullname FROM users WHERE id = 2')->fetch(PDO::FETCH_ASSOC);

        return [(string)$row['username'], (int)$row['active'], (string)$row['fullname']];
    }
}
