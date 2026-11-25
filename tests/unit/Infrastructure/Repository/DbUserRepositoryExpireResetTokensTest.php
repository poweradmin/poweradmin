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
use Poweradmin\Infrastructure\Database\PDOCommon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

#[CoversClass(DbUserRepository::class)]
#[CoversClass(DbPasswordResetTokenRepository::class)]
class DbUserRepositoryExpireResetTokensTest extends TestCase
{
    private PDOCommon $db;
    private DbUserRepository $users;
    private DbPasswordResetTokenRepository $tokens;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required');
        }

        $this->db = new PDOCommon('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, fullname TEXT, email TEXT, auth_method TEXT, use_ldap INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE password_reset_tokens (
            id INTEGER PRIMARY KEY,
            email TEXT NOT NULL,
            token TEXT NOT NULL,
            created_at TEXT,
            expires_at TEXT,
            ip_address TEXT,
            used INTEGER NOT NULL DEFAULT 0
        )");
        $this->db->exec("INSERT INTO users (id, username, password, email) VALUES (1, 'a', 'old', 'a@example.com'), (2, 'b', 'old', 'b@example.com')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn ($group, $key, $default = null) => ($group === 'database' && $key === 'type') ? 'sqlite' : $default
        );

        $this->users = new DbUserRepository($this->db, $config);
        $this->tokens = new DbPasswordResetTokenRepository($this->db, $config);
    }

    private function issue(string $email, string $token): void
    {
        $this->tokens->create(['email' => $email, 'token' => $token, 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
    }

    public function testPasswordChangeRefusesEveryOutstandingTokenOfThatAccountOnly(): void
    {
        $this->issue('a@example.com', 'first');
        $this->issue('a@example.com', 'second');
        $this->issue('b@example.com', 'other');

        $this->assertTrue($this->users->updatePassword(1, 'new'));

        $this->assertNull($this->tokens->findByToken('first'));
        $this->assertNull($this->tokens->findByToken('second'));
        $this->assertNotNull($this->tokens->findByToken('other'));
    }

    public function testRedeemingOneTokenRefusesTheOther(): void
    {
        $this->issue('a@example.com', 'first');
        $this->issue('a@example.com', 'second');
        $id = (int) $this->tokens->findByToken('first')['id'];

        $this->assertTrue($this->tokens->markAsUsed($id));
        $this->users->updatePassword(1, 'new');

        $this->assertNull($this->tokens->findByToken('second'));
    }

    public function testUpdateUserWithPasswordRetiresTokensButOtherFieldsDoNot(): void
    {
        $this->issue('a@example.com', 'first');

        $this->users->updateUser(1, ['fullname' => 'x']);
        $this->assertNotNull($this->tokens->findByToken('first'));

        $this->users->updateUser(1, ['password' => 'new']);
        $this->assertNull($this->tokens->findByToken('first'));
    }

    public function testEmailAndPasswordChangedInOneSaveRetireTheOldAddressTokens(): void
    {
        $this->issue('a@example.com', 'first');

        $this->assertTrue($this->users->updateUser(1, ['email' => 'new@example.com', 'password' => 'new']));

        $this->assertNull($this->tokens->findByToken('first'));
    }

    public function testAddressSharedByTwoAccountsIsLeftAlone(): void
    {
        $this->db->exec("UPDATE users SET email = 'a@example.com' WHERE id = 2");
        $this->issue('a@example.com', 'first');

        $this->users->updatePassword(1, 'new');

        $this->assertNotNull($this->tokens->findByToken('first'));
    }

    public function testAnOldAddressStillHeldByAnotherAccountIsLeftAlone(): void
    {
        $this->db->exec("UPDATE users SET email = 'a@example.com' WHERE id = 2");
        $this->issue('a@example.com', 'shared');

        $this->assertTrue($this->users->updateUser(1, ['email' => 'y@example.com', 'password' => 'new']));

        $this->assertNotNull($this->tokens->findByToken('shared'));
    }
}
