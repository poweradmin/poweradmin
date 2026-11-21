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
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;

/**
 * The request throttle counts reset rows, so an expired row must stay while the throttle
 * window still covers it, even when the token lifetime is shorter than that window.
 */
class DbPasswordResetTokenRetentionTest extends TestCase
{
    private PDO $db;
    private DbPasswordResetTokenRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT, token TEXT, created_at TEXT, expires_at TEXT, ip_address TEXT, used INTEGER DEFAULT 0)');

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key): mixed => $key === 'type' ? 'sqlite' : null);
        $this->repository = new DbPasswordResetTokenRepository($this->db, $config);
    }

    public function testAnExpiredRowInsideTheRetentionWindowIsKept(): void
    {
        $this->insertToken('a@example.com', 600, 300);

        $this->assertSame(0, $this->repository->deleteExpired(3600));
        $this->assertSame(1, $this->rows());
    }

    public function testAnExpiredRowPastTheRetentionWindowIsDeleted(): void
    {
        $this->insertToken('a@example.com', 7200, 6900);

        $this->assertSame(1, $this->repository->deleteExpired(3600));
        $this->assertSame(0, $this->rows());
    }

    public function testWithoutARetentionWindowEveryExpiredRowGoes(): void
    {
        $this->insertToken('a@example.com', 600, 300);

        $this->assertSame(1, $this->repository->deleteExpired());
        $this->assertSame(0, $this->rows());
    }

    public function testAValidRowIsNeverDeleted(): void
    {
        $this->insertToken('a@example.com', 100, -800);

        $this->assertSame(0, $this->repository->deleteExpired(0));
        $this->assertSame(0, $this->repository->deleteExpired(3600));
        $this->assertSame(1, $this->rows());
    }

    private function insertToken(string $email, int $createdAgo, int $expiredAgo): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO password_reset_tokens (email, token, created_at, expires_at, ip_address, used)
             VALUES (:email, 't', datetime('now', :created), :expires, '192.0.2.1', 0)"
        );
        $stmt->execute([
            ':email' => $email,
            ':created' => "-$createdAgo seconds",
            ':expires' => date('Y-m-d H:i:s', time() - $expiredAgo),
        ]);
    }

    private function rows(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn();
    }
}
