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
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;

#[CoversClass(DbPasswordResetTokenRepository::class)]
class DbPasswordResetTokenRepositoryClaimTest extends TestCase
{
    private DbPasswordResetTokenRepository $repo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required');
        }

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE password_reset_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used INTEGER NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) DEFAULT NULL
        )');

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );

        $this->repo = new DbPasswordResetTokenRepository($db, $config);
    }

    private function createToken(string $token): int
    {
        $this->repo->create([
            'email' => 'user@example.com',
            'token' => $token,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);

        return (int) $this->repo->findByToken($token)['id'];
    }

    public function testTokenCanBeClaimedOnlyOnce(): void
    {
        $id = $this->createToken('one-shot');

        $this->assertTrue($this->repo->markAsUsed($id));
        $this->assertFalse($this->repo->markAsUsed($id), 'A second redemption of the same token must not succeed.');
        $this->assertNull($this->repo->findByToken('one-shot'));
    }

    public function testAReleasedClaimMakesTheLinkUsableAgain(): void
    {
        $id = $this->createToken('retry');

        $this->assertTrue($this->repo->markAsUsed($id));
        $this->assertTrue($this->repo->releaseClaim($id));
        $this->assertNotNull($this->repo->findByToken('retry'));
        $this->assertTrue($this->repo->markAsUsed($id));
    }
}
