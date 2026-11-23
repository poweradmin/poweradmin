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


namespace unit;

use PDOStatement;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * A rename must be checked against existing usernames even when the old and new
 * names compare equal as numbers ('100' == '1e2' with loose comparison).
 *
 * Separate processes keep verify_permission()'s static cache from leaking.
 */
#[RunTestsInSeparateProcesses]
class UserManagerUsernameRenameTest extends TestCase
{
    private const CALLER_ID = 1;
    private const TARGET_ID = 2;

    public function testEditUserRefusesNumericallyEqualNameThatIsTaken(): void
    {
        $manager = new UserManager($this->db(), $this->createMock(AppConfiguration::class));

        $this->expectOutputRegex('/Username exist already/');
        $this->assertFalse($manager->edit_user(self::TARGET_ID, '1e2', 'Full', 'a@example.com', '1', '', 1, '', false));
    }

    public function testUpdateUserDetailsRefusesNumericallyEqualNameThatIsTaken(): void
    {
        $manager = new UserManager($this->db(), $this->createMock(AppConfiguration::class));

        $this->expectOutputRegex('/Username exist already/');
        $this->assertFalse($manager->update_user_details([
            'uid' => self::TARGET_ID,
            'username' => '1e2',
            'first_name' => 'Full',
            'email' => 'a@example.com',
            'templ_id' => 1,
            'descr' => '',
        ]));
    }

    public function testUpdateUserDetailsKeepsOwnUsernameAndEmail(): void
    {
        $writes = [];
        $db = $this->db($writes);
        $manager = new UserManager($db, $this->createMock(AppConfiguration::class));

        $manager->update_user_details([
            'uid' => self::CALLER_ID,
            'username' => 'mallory',
            'fullname' => 'Full',
            'email' => 'attacker@example.net',
            'templ_id' => 1,
            'descr' => '',
        ]);

        $this->assertSame('100', $writes[':username'] ?? null);
        $this->assertSame('stored@example.com', $writes[':email'] ?? null);
    }

    /** @return list<array{0: mixed}> */
    public static function invalidUids(): array
    {
        return [['1abc'], ['1 '], [[1]], [''], ['-1'], [null]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUids')]
    public function testUpdateUserDetailsRefusesANonNumericUidAndWritesNothing(mixed $uid): void
    {
        $binds = [];
        $manager = new UserManager($this->db($binds), $this->createMock(AppConfiguration::class));

        $result = $manager->update_user_details([
            'uid' => $uid,
            'username' => 'mallory',
            'fullname' => 'Full',
            'email' => 'attacker@example.net',
            'templ_id' => 1,
            'descr' => '',
        ]);

        $this->assertFalse($result);
        $this->assertSame([], $binds);
    }

    public function testANumericStringOwnUidIsStillTheOwnRow(): void
    {
        $binds = [];
        $manager = new UserManager($this->db($binds), $this->createMock(AppConfiguration::class));

        $manager->update_user_details([
            'uid' => (string)self::CALLER_ID,
            'username' => 'mallory',
            'fullname' => 'Full',
            'email' => 'attacker@example.net',
            'templ_id' => 1,
            'descr' => '',
        ]);

        $this->assertSame('stored@example.com', $binds[':email'] ?? null);
    }

    private function db(array &$binds = []): PDOLayer
    {
        $_SESSION['userid'] = self::CALLER_ID;

        $permissions = $this->createMock(PDOStatement::class);
        $permissions->method('fetchAll')->willReturn(['user_edit_others' => [[]], 'user_edit_own' => [[]]]);

        $current = $this->createMock(PDOStatement::class);
        $current->method('fetch')->willReturn(['username' => '100', 'email' => 'stored@example.com', 'perm_templ' => 1, 'active' => 1, 'use_ldap' => 0]);

        $current->method('bindValue')->willReturnCallback(function ($key, $value) use (&$binds): bool {
            $binds[$key] = $value;
            return true;
        });

        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value): string => "'" . $value . "'");
        $db->method('prepare')->willReturnCallback(
            fn(string $sql): PDOStatement => str_contains($sql, 'perm_items') ? $permissions : $current
        );
        $db->method('query')->willReturn($current);
        // Target is not a superuser, email is free, and the wanted name '1e2' is taken
        $db->method('queryOne')->willReturnCallback(
            fn(string $sql): mixed => str_contains($sql, "username = '1e2'") ? self::CALLER_ID : false
        );

        return $db;
    }
}
