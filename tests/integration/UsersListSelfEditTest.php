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

namespace Poweradmin\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * A user who may edit only their own account keeps the stored username and active flag when
 * their row is saved from the users list, as on the single user form (#1327).
 */
class UsersListSelfEditTest extends TestCase
{
    private const SELF = 5;
    private const OTHER = 6;

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec(
            "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, fullname TEXT NOT NULL DEFAULT '', "
            . "email TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1, perm_templ INTEGER NOT NULL, "
            . "use_ldap INTEGER NOT NULL DEFAULT 0, auth_method TEXT NULL, perm_templ_source TEXT NULL, password TEXT NULL)"
        );
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER NOT NULL, perm_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'user_edit_own'), (2, 'user_edit_others')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'self only'), (2, 'managers')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1), (2, 1), (2, 2)");
        $this->db->exec("INSERT INTO users (id, username, fullname, email, active, perm_templ) VALUES (5, 'alice', 'Alice', 'alice@example.com', 1, 1), (6, 'bob', 'Bob', 'bob@example.com', 1, 1)");

        $_SESSION['userid'] = self::SELF;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    #[RunInSeparateProcess]
    public function testOwnRowKeepsUsernameAndActiveWithoutEditOthers(): void
    {
        $this->assertTrue($this->save(['uid' => self::SELF, 'username' => 'mallory', 'fullname' => 'Alice A', 'email' => 'alice@example.com']));

        $row = $this->row(self::SELF);
        $this->assertSame('alice', $row['username']);
        $this->assertSame(1, (int)$row['active']);
        $this->assertSame('Alice A', $row['fullname']);
    }

    #[RunInSeparateProcess]
    public function testOwnRowKeepsUsernameEmailAndPasswordEvenWithEditOthers(): void
    {
        $this->db->exec("UPDATE users SET perm_templ = 2, password = 'stored-hash' WHERE id = " . self::SELF);

        $this->assertTrue($this->save(['uid' => self::SELF, 'username' => 'alice2', 'fullname' => 'Alice B', 'email' => 'attacker@example.net', 'password' => 'NewPass123!x', 'active' => 'on']));

        $row = $this->row(self::SELF);
        $this->assertSame('alice', $row['username']);
        $this->assertSame('alice@example.com', $row['email']);
        $this->assertSame('stored-hash', $row['password']);
        $this->assertSame('Alice B', $row['fullname']);
    }

    #[RunInSeparateProcess]
    public function testAnotherRowIsStillEditableWithEditOthers(): void
    {
        $this->db->exec("UPDATE users SET perm_templ = 2 WHERE id = " . self::SELF);

        $this->assertTrue($this->save(['uid' => self::OTHER, 'username' => 'bobby', 'fullname' => 'Bob', 'email' => 'bob@example.com']));

        $row = $this->row(self::OTHER);
        $this->assertSame('bobby', $row['username']);
        $this->assertSame(0, (int)$row['active']);
    }

    /** @return list<array{0: mixed}> */
    public static function invalidUids(): array
    {
        return [['1abc'], ['5abc'], [[5]], [''], ['-5'], [null]];
    }

    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUids')]
    public function testNonNumericUidIsRefusedAndNothingIsWritten(mixed $uid): void
    {
        $this->db->exec("UPDATE users SET perm_templ = 2, password = 'stored-hash' WHERE id = " . self::SELF);

        $this->assertFalse($this->save(['uid' => $uid, 'username' => 'alice', 'fullname' => 'Alice B', 'email' => 'attacker@example.net', 'password' => 'NewPass123!x', 'active' => 'on']));

        $row = $this->row(self::SELF);
        $this->assertSame('alice@example.com', $row['email']);
        $this->assertSame('Alice', $row['fullname']);
        $this->assertSame('stored-hash', $row['password']);
    }

    #[RunInSeparateProcess]
    public function testNumericStringOwnUidIsStillTheOwnRow(): void
    {
        $this->db->exec("UPDATE users SET perm_templ = 2 WHERE id = " . self::SELF);

        $this->assertTrue($this->save(['uid' => (string)self::SELF, 'username' => 'alice', 'fullname' => 'Alice', 'email' => 'attacker@example.net', 'active' => 'on']));

        $this->assertSame('alice@example.com', $this->row(self::SELF)['email']);
    }

    /** @param array<string, mixed> $details */
    private function save(array $details): bool
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, mixed $default = null): mixed => $default);

        return (new UserManager($this->db, $config))->updateUserDetails($details);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return $this->db->query("SELECT * FROM users WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    }
}
