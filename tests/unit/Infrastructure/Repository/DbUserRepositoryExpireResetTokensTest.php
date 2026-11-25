<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * Setting a password spends the reset links requested before it.
 */
class DbUserRepositoryExpireResetTokensTest extends TestCase
{
    private PDO $db;
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, fullname TEXT, email TEXT NOT NULL DEFAULT '')");
        $this->db->exec("CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("INSERT INTO users VALUES (1, 'a', 'old', '', 'a@example.com'), (2, 'b', 'old', '', 'b@example.com')");
        $this->db->exec("INSERT INTO password_reset_tokens (email) VALUES ('a@example.com'), ('b@example.com')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn($group, $key) => $key === 'type' ? 'sqlite' : null);
        $this->repository = new DbUserRepository($this->db, $config);
    }

    private function used(string $email): int
    {
        return (int) $this->db->query("SELECT used FROM password_reset_tokens WHERE email = '$email'")->fetchColumn();
    }

    public function testUpdatePasswordSpendsOutstandingResetTokens(): void
    {
        $this->assertTrue($this->repository->updatePassword(1, 'new'));

        $this->assertSame(1, $this->used('a@example.com'));
        $this->assertSame(0, $this->used('b@example.com'));
    }

    public function testUpdateUserWithAPasswordSpendsOutstandingResetTokens(): void
    {
        $this->assertTrue($this->repository->updateUser(1, ['password' => 'new']));

        $this->assertSame(1, $this->used('a@example.com'));
    }

    public function testUpdateUserWithoutAPasswordLeavesTokensAlone(): void
    {
        $this->assertTrue($this->repository->updateUser(1, ['fullname' => 'A', 'password' => '']));

        $this->assertSame(0, $this->used('a@example.com'));
    }

    public function testEmailAndPasswordChangedInOneSaveSpendTheOldAddressTokens(): void
    {
        $this->assertTrue($this->repository->updateUser(1, ['email' => 'new@example.com', 'password' => 'new']));

        $this->assertSame(1, $this->used('a@example.com'));
        $this->assertSame(0, $this->used('b@example.com'));
    }

    public function testAnOldAddressStillHeldByAnotherAccountIsLeftAlone(): void
    {
        $this->db->exec("UPDATE users SET email = 'a@example.com' WHERE id = 2");
        $this->db->exec("DELETE FROM password_reset_tokens WHERE email = 'b@example.com'");

        $this->assertTrue($this->repository->updateUser(1, ['email' => 'new@example.com', 'password' => 'new']));

        $this->assertSame(0, $this->used('a@example.com'));
    }
}
