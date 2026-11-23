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
    public function testOwnRowKeepsUsernameEmailAndPasswordEvenWithEditOthers(): void
    {
        $this->db->exec("UPDATE users SET password = 'stored-hash' WHERE id = 2");
        $row = $this->row('renamed', null);
        $row['email'] = 'attacker@example.net';
        $row['password'] = 'NewPass123!x';

        $result = $this->manager(['user_edit_own', 'user_edit_others', 'user_passwd_edit_others'])->updateUserDetails($row);

        $this->assertTrue($result);
        $this->assertSame(['alice', 0, 'New Name'], $this->stored());
        $this->assertSame(['alice@example.com', 'stored-hash'], $this->db->query('SELECT email, password FROM users WHERE id = 2')->fetch(PDO::FETCH_NUM));
    }

    #[Test]
    public function testAnotherRowStillTakesUsernameAndPassword(): void
    {
        $this->db->exec("INSERT INTO users (id, username, fullname, email, description, perm_templ, active, use_ldap, auth_method, password)
            VALUES (3, 'bob', 'Bob', 'bob@example.com', '', 2, 1, 0, 'sql', 'old')");
        $this->db->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT, used INTEGER DEFAULT 0)');
        $row = ['uid' => 3, 'username' => 'bobby', 'fullname' => 'Bob', 'email' => 'bob@example.com', 'templ_id' => 2, 'active' => 'on', 'password' => 'NewPass123!x'];

        $result = $this->manager(['user_edit_own', 'user_edit_others', 'user_passwd_edit_others'])->updateUserDetails($row);

        $this->assertTrue($result);
        $stored = $this->db->query('SELECT username, password FROM users WHERE id = 3')->fetch(PDO::FETCH_NUM);
        $this->assertSame('bobby', $stored[0]);
        $this->assertNotSame('old', $stored[1]);
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function invalidUids(): array
    {
        return [['1abc'], ['2abc'], [[2]], [''], ['-2'], [null]];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUids')]
    public function testNonNumericUidIsRefusedAndNothingIsWritten(mixed $uid): void
    {
        $row = $this->row('alice', null);
        $row['uid'] = $uid;
        $row['email'] = 'attacker@example.net';
        $row['password'] = 'NewPass123!x';

        $result = $this->manager(['user_edit_own', 'user_edit_others', 'user_passwd_edit_others'])->updateUserDetails($row);

        $this->assertFalse($result);
        $this->assertSame(['alice', 1, 'Alice'], $this->stored());
        $this->assertSame('alice@example.com', $this->db->query('SELECT email FROM users WHERE id = 2')->fetchColumn());
    }

    #[Test]
    public function testNumericStringOwnUidIsStillTheOwnRow(): void
    {
        $row = $this->row('renamed', null);
        $row['uid'] = '2';
        $row['email'] = 'attacker@example.net';

        $result = $this->manager(['user_edit_own', 'user_edit_others'])->updateUserDetails($row);

        $this->assertTrue($result);
        $this->assertSame('alice@example.com', $this->db->query('SELECT email FROM users WHERE id = 2')->fetchColumn());
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
