<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

use DateTime;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;

#[CoversClass(DbUserMfaRepository::class)]
class DbUserMfaRepositoryTest extends TestCase
{
    private DbUserMfaRepository $repository;
    private PDOCommon&MockObject $db;
    private ConfigurationManager&MockObject $config;
    private string $originalErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress error_log output during tests
        $this->originalErrorLog = ini_get('error_log') ?: '';
        ini_set('error_log', '/dev/null');

        $this->db = $this->createMock(PDOCommon::class);
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->repository = new DbUserMfaRepository($this->db, $this->config);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalErrorLog);
        parent::tearDown();
    }

    private function createMfaDbRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'user_id' => 1,
            'enabled' => 1,
            'secret' => 'JBSWY3DPEHPK3PXP',
            'recovery_codes' => json_encode(['CODE1', 'CODE2', 'CODE3']),
            'type' => 'app',
            'last_used_at' => null,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => null,
            'verification_data' => null
        ], $overrides);
    }

    // ========== findByUserId tests ==========

    #[Test]
    public function testFindByUserIdReturnsUserMfaWhenFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($this->createMfaDbRow());

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findByUserId(1);

        $this->assertInstanceOf(UserMfa::class, $result);
        $this->assertEquals(1, $result->getUserId());
        $this->assertTrue($result->isEnabled());
    }

    #[Test]
    public function testFindByUserIdReturnsNullWhenNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findByUserId(999);

        $this->assertNull($result);
    }

    // ========== findById tests ==========

    #[Test]
    public function testFindByIdReturnsUserMfaWhenFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($this->createMfaDbRow(['id' => 5]));

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findById(5);

        $this->assertInstanceOf(UserMfa::class, $result);
        $this->assertEquals(5, $result->getId());
    }

    #[Test]
    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findById(999);

        $this->assertNull($result);
    }

    // ========== save tests ==========

    #[Test]
    public function testSaveInsertsNewUserMfa(): void
    {
        $userMfa = new UserMfa(
            0, // ID 0 means new
            1,
            false,
            'NEWSECRET123',
            json_encode(['RC1', 'RC2']),
            'app',
            null,
            new DateTime('2024-01-01'),
            null
        );

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);

        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetch')->willReturn($this->createMfaDbRow(['id' => 10]));

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($insertStmt, $selectStmt);

        $this->db->method('lastInsertId')->willReturn('10');

        $result = $this->repository->save($userMfa);

        $this->assertInstanceOf(UserMfa::class, $result);
    }

    #[Test]
    public function testSaveUpdatesExistingUserMfa(): void
    {
        $userMfa = new UserMfa(
            5, // Has ID - existing record
            1,
            true,
            'UPDATEDSECRET',
            json_encode(['RC1']),
            'app',
            null,
            new DateTime('2024-01-01'),
            new DateTime('2024-06-01')
        );

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->with($this->stringContains('UPDATE user_mfa'))
            ->willReturn($stmt);

        $result = $this->repository->save($userMfa);

        $this->assertEquals(5, $result->getId());
    }

    // ========== delete tests ==========

    #[Test]
    public function testDeleteReturnsTrueOnSuccess(): void
    {
        $userMfa = new UserMfa(
            1,
            1,
            true,
            'SECRET',
            null,
            'app',
            null,
            new DateTime(),
            null
        );

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->with($this->stringContains('DELETE FROM user_mfa'))
            ->willReturn($stmt);

        $result = $this->repository->delete($userMfa);

        $this->assertTrue($result);
    }

    // ========== findAllEnabled tests ==========

    #[Test]
    public function testFindAllEnabledReturnsEnabledMfaRecords(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            $this->createMfaDbRow(['id' => 1, 'enabled' => 1]),
            $this->createMfaDbRow(['id' => 2, 'enabled' => 1])
        ]);

        $this->config->method('get')
            ->with('database', 'type')
            ->willReturn('mysql');

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findAllEnabled();

        $this->assertCount(2, $result);
        $this->assertTrue($result[0]->isEnabled());
        $this->assertTrue($result[1]->isEnabled());
    }

    #[Test]
    public function testFindAllEnabledReturnsEmptyArrayWhenNone(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);

        $this->config->method('get')
            ->with('database', 'type')
            ->willReturn('mysql');

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findAllEnabled();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ========== Data mapping tests ==========

    #[Test]
    public function testFindByUserIdMapsAllFieldsCorrectly(): void
    {
        $dbRow = [
            'id' => 42,
            'user_id' => 10,
            'enabled' => 1,
            'secret' => 'MYSECRETKEY123',
            'recovery_codes' => '["CODE1","CODE2"]',
            'type' => 'email',
            'last_used_at' => '2024-06-15 10:30:00',
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-06-01 12:00:00',
            'verification_data' => '{"code":"123456"}'
        ];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($dbRow);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findByUserId(10);

        $this->assertEquals(42, $result->getId());
        $this->assertEquals(10, $result->getUserId());
        $this->assertTrue($result->isEnabled());
        $this->assertEquals('MYSECRETKEY123', $result->getSecret());
        $this->assertEquals('["CODE1","CODE2"]', $result->getRecoveryCodes());
        $this->assertEquals('email', $result->getType());
        $this->assertNotNull($result->getLastUsedAt());
        $this->assertNotNull($result->getUpdatedAt());
        $this->assertEquals('{"code":"123456"}', $result->getVerificationData());
    }

    #[Test]
    public function testFindByUserIdHandlesDisabledMfa(): void
    {
        $dbRow = $this->createMfaDbRow(['enabled' => 0]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($dbRow);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findByUserId(1);

        $this->assertFalse($result->isEnabled());
    }

    #[Test]
    public function testFindByUserIdHandlesNullOptionalFields(): void
    {
        $dbRow = $this->createMfaDbRow([
            'last_used_at' => null,
            'updated_at' => null,
            'verification_data' => null
        ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($dbRow);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findByUserId(1);

        $this->assertNull($result->getLastUsedAt());
        $this->assertNull($result->getUpdatedAt());
        $this->assertNull($result->getVerificationData());
    }

    #[Test]
    public function testFindByUserIdHandlesDifferentMfaTypes(): void
    {
        $dbRow = $this->createMfaDbRow(['type' => 'email']);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($dbRow);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findByUserId(1);

        $this->assertEquals('email', $result->getType());
    }
}
