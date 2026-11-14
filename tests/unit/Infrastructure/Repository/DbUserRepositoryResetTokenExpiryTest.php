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
namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * Setting a password spends the reset links requested earlier for the account's address.
 */
#[CoversClass(DbUserRepository::class)]
class DbUserRepositoryResetTokenExpiryTest extends TestCase
{
    private PDO $db;
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, fullname TEXT, email TEXT NOT NULL DEFAULT '')",
                "CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)",
                "INSERT INTO users VALUES (1, 'a', 'old', 'A', 'a@example.com'), (2, 'b', 'old', 'B', 'b@example.com'),
                    (3, 'c', 'old', 'C', 'shared@example.com'), (4, 'd', 'old', 'D', 'shared@example.com')",
                "INSERT INTO password_reset_tokens (email) VALUES ('a@example.com'), ('b@example.com'), ('shared@example.com')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $this->repository = new DbUserRepository($this->db, $this->createMock(ConfigurationManager::class));
    }

    private function unused(string $email): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM password_reset_tokens WHERE used = 0 AND email = '$email'")->fetchColumn();
    }

    public function testUpdatePasswordSpendsOutstandingResetTokens(): void
    {
        $this->assertTrue($this->repository->updatePassword(1, 'new-hash'));

        $this->assertSame(0, $this->unused('a@example.com'));
        $this->assertSame(1, $this->unused('b@example.com'));
    }

    public function testUpdateUserWithAPasswordSpendsOutstandingResetTokens(): void
    {
        $this->assertTrue($this->repository->updateUser(1, ['password' => 'new-hash']));

        $this->assertSame(0, $this->unused('a@example.com'));
    }

    public function testUpdateUserWithoutAPasswordLeavesResetTokens(): void
    {
        $this->assertTrue($this->repository->updateUser(1, ['fullname' => 'Renamed']));

        $this->assertSame(1, $this->unused('a@example.com'));
    }

    public function testASharedAddressIsLeftAlone(): void
    {
        $this->assertTrue($this->repository->updatePassword(3, 'new-hash'));

        $this->assertSame(1, $this->unused('shared@example.com'));
    }
}
