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
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use TestHelpers\FakeConfiguration;

/**
 * A reset link requested before a password was set, perhaps by an attacker, must stop
 * working once the password changes by any route.
 */
#[CoversClass(DbUserRepository::class)]
class DbUserRepositoryResetTokensTest extends TestCase
{
    private PDO $db;
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, fullname TEXT, email TEXT NOT NULL DEFAULT '', description TEXT, active INTEGER, perm_templ INTEGER)",
                "CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)",
                "INSERT INTO users (id, username, password, email) VALUES (1, 'jane', 'old', 'jane@example.com'), (2, 'bob', 'old', 'bob@example.com'), (3, 'ann', 'old', 'team@example.com'), (4, 'tom', 'old', 'team@example.com')",
                "INSERT INTO password_reset_tokens (email) VALUES ('Jane@Example.com'), ('bob@example.com'), ('team@example.com')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $this->repository = new DbUserRepository($this->db, new FakeConfiguration(['database' => ['type' => 'sqlite']]), false);
    }

    /**
     * Addresses whose links still work. Spent rows stay, since the request limit counts them.
     *
     * @return list<string>
     */
    private function tokenEmails(): array
    {
        $this->assertSame(3, (int)$this->db->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());

        return $this->db->query('SELECT email FROM password_reset_tokens WHERE used = 0 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testChangingAPasswordDropsThatAccountsResetLinks(): void
    {
        $this->assertTrue($this->repository->updatePassword(1, 'new-hash'));

        $this->assertSame(['bob@example.com', 'team@example.com'], $this->tokenEmails());
    }

    public function testAnAdminSettingThePasswordDropsThemToo(): void
    {
        $this->assertTrue($this->repository->updateUser(2, new UpdateUserCommand(password: 'new-hash')));

        $this->assertSame(['Jane@Example.com', 'team@example.com'], $this->tokenEmails());
    }

    public function testAnEditWithoutAPasswordKeepsThem(): void
    {
        $this->assertTrue($this->repository->updateUser(2, new UpdateUserCommand(fullname: 'Bob')));

        $this->assertCount(3, $this->tokenEmails());
    }

    public function testAnAddressSharedWithAnotherAccountIsLeftAlone(): void
    {
        $this->assertTrue($this->repository->updatePassword(3, 'new-hash'));

        $this->assertContains('team@example.com', $this->tokenEmails());
    }
}
