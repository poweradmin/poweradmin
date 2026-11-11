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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Repository\UserMfaRepositoryInterface;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;

/**
 * Parallel second-factor guesses each read the same stored failure total. The
 * count has to move atomically and before the code is checked.
 */
class MfaVerificationAttemptCountIntegrationTest extends TestCase
{
    private PDOCommon $db;
    private DbUserMfaRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDOCommon('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec(
            'CREATE TABLE user_mfa (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL UNIQUE, enabled INTEGER NOT NULL DEFAULT 0, '
            . 'secret TEXT NULL, recovery_codes TEXT NULL, type TEXT NOT NULL DEFAULT \'app\', last_used_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NULL, verification_data TEXT NULL)'
        );
        $this->db->exec("INSERT INTO user_mfa (user_id, enabled, secret, type, created_at) VALUES (5, 1, 'JBSWY3DPEHPK3PXP', 'app', '2026-01-01 00:00:00')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn('sqlite');
        $this->repository = new DbUserMfaRepository($this->db, $config);
    }

    public function testReserveRefusesAfterTheLimitWithoutAnyStoredTotal(): void
    {
        $this->assertTrue($this->repository->reserveVerificationAttempt(5, 2, 900));
        $this->assertTrue($this->repository->reserveVerificationAttempt(5, 2, 900));
        $this->assertFalse($this->repository->reserveVerificationAttempt(5, 2, 900));
    }

    public function testParallelGuessesThatAllSawAnEmptyRecordStillHitTheLimit(): void
    {
        // Every request in a burst loads the record before any of them writes.
        $stale = $this->repository->findByUserId(5);
        $service = new MfaService(
            $this->staleReads($stale),
            $this->createMock(ConfigurationManager::class),
            $this->createMock(MailService::class)
        );

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($service->verifyCode(5, '000000'));
        }

        $stored = $this->repository->findByUserId(5)->getVerificationDataAsArray();
        $this->assertGreaterThan(time(), $stored['locked_until'] ?? 0, 'five parallel wrong guesses must start the lockout');
    }

    private function staleReads(UserMfa $snapshot): UserMfaRepositoryInterface
    {
        $real = $this->repository;

        return new class ($real, $snapshot) implements UserMfaRepositoryInterface {
            public function __construct(private DbUserMfaRepository $real, private UserMfa $snapshot)
            {
            }

            public function findByUserId(int $userId): ?UserMfa
            {
                return clone $this->snapshot;
            }

            public function findById(int $id): ?UserMfa
            {
                return $this->real->findById($id);
            }

            public function save(UserMfa $userMfa): UserMfa
            {
                return $this->real->save($userMfa);
            }

            public function delete(UserMfa $userMfa): bool
            {
                return $this->real->delete($userMfa);
            }

            public function reserveVerificationAttempt(int $userId, int $maxAttempts, int $lockSeconds): bool
            {
                return $this->real->reserveVerificationAttempt($userId, $maxAttempts, $lockSeconds);
            }

            public function findAllEnabled(): array
            {
                return $this->real->findAllEnabled();
            }
        };
    }
}
