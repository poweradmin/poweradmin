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
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;

#[CoversClass(DbApiKeyRepository::class)]
class DbApiKeyRepositoryTest extends TestCase
{
    private DbApiKeyRepository $repository;
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
        $this->repository = new DbApiKeyRepository($this->db, $this->config);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalErrorLog);
        parent::tearDown();
    }

    private function createApiKeyDbRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Test API Key',
            'secret_key' => 'pwa_test_secret_key_123',
            'created_by' => 1,
            'created_at' => '2024-01-01 00:00:00',
            'last_used_at' => null,
            'disabled' => 0,
            'expires_at' => null
        ], $overrides);
    }

    // ========== findById tests ==========

    #[Test]
    public function testFindByIdReturnsApiKeyWhenFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($this->createApiKeyDbRow());

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findById(1);

        $this->assertInstanceOf(ApiKey::class, $result);
        $this->assertEquals(1, $result->getId());
        $this->assertEquals('Test API Key', $result->getName());
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

    // ========== getAll tests ==========

    #[Test]
    public function testGetAllReturnsAllApiKeys(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            $this->createApiKeyDbRow(['id' => 1, 'name' => 'Key 1']),
            $this->createApiKeyDbRow(['id' => 2, 'name' => 'Key 2'])
        ]);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->getAll();

        $this->assertCount(2, $result);
        $this->assertEquals('Key 1', $result[0]->getName());
        $this->assertEquals('Key 2', $result[1]->getName());
    }

    #[Test]
    public function testGetAllWithUserIdFiltersByUser(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            $this->createApiKeyDbRow(['id' => 1, 'created_by' => 5])
        ]);

        $this->db->method('prepare')
            ->with($this->stringContains('WHERE created_by = :userId'))
            ->willReturn($stmt);

        $result = $this->repository->getAll(5);

        $this->assertCount(1, $result);
        $this->assertEquals(5, $result[0]->getCreatedBy());
    }

    #[Test]
    public function testGetAllReturnsEmptyArrayWhenNoKeys(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->getAll();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ========== save tests ==========

    #[Test]
    public function testSaveInsertsNewApiKey(): void
    {
        $apiKey = new ApiKey(
            'New API Key',
            'pwa_new_secret_key',
            1,
            new DateTime('2024-01-01'),
            null,
            false,
            null,
            null // No ID - new key
        );

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->with($this->stringContains('INSERT INTO api_keys'))
            ->willReturn($stmt);

        $this->db->method('lastInsertId')->willReturn('42');

        $result = $this->repository->save($apiKey);

        $this->assertEquals(42, $result->getId());
    }

    #[Test]
    public function testSaveUpdatesExistingApiKey(): void
    {
        $apiKey = new ApiKey(
            'Updated API Key',
            'pwa_updated_secret_key',
            1,
            new DateTime('2024-01-01'),
            null,
            false,
            null,
            5 // Has ID - existing key
        );

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->with($this->stringContains('UPDATE api_keys'))
            ->willReturn($stmt);

        $result = $this->repository->save($apiKey);

        $this->assertEquals(5, $result->getId());
        $this->assertEquals('Updated API Key', $result->getName());
    }

    // ========== delete tests ==========

    #[Test]
    public function testDeleteReturnsTrueOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);

        $this->db->method('prepare')
            ->with($this->stringContains('DELETE FROM api_keys'))
            ->willReturn($stmt);

        $result = $this->repository->delete(1);

        $this->assertTrue($result);
    }

    #[Test]
    public function testDeleteReturnsFalseWhenNoRowsAffected(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn(0);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->delete(999);

        $this->assertFalse($result);
    }

    // ========== updateLastUsed tests ==========

    #[Test]
    public function testUpdateLastUsedReturnsTrueOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);

        $this->config->method('get')
            ->with('database', 'type')
            ->willReturn('mysql');

        $this->db->method('prepare')
            ->with($this->stringContains('UPDATE api_keys SET last_used_at'))
            ->willReturn($stmt);

        $result = $this->repository->updateLastUsed(1);

        $this->assertTrue($result);
    }

    // ========== disable/enable tests ==========

    #[Test]
    public function testDisableReturnsTrueOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);

        $this->config->method('get')
            ->with('database', 'type')
            ->willReturn('mysql');

        $this->db->method('prepare')
            ->with($this->stringContains('UPDATE api_keys SET disabled'))
            ->willReturn($stmt);

        $result = $this->repository->disable(1);

        $this->assertTrue($result);
    }

    #[Test]
    public function testEnableReturnsTrueOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);

        $this->config->method('get')
            ->with('database', 'type')
            ->willReturn('mysql');

        $this->db->method('prepare')
            ->with($this->stringContains('UPDATE api_keys SET disabled'))
            ->willReturn($stmt);

        $result = $this->repository->enable(1);

        $this->assertTrue($result);
    }

    // ========== countByUser tests ==========

    #[Test]
    public function testCountByUserReturnsCorrectCount(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(5);

        $this->db->method('prepare')
            ->with($this->stringContains('SELECT COUNT(*)'))
            ->willReturn($stmt);

        $result = $this->repository->countByUser(1);

        $this->assertEquals(5, $result);
    }

    #[Test]
    public function testCountByUserReturnsZeroWhenNoKeys(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(0);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->countByUser(999);

        $this->assertEquals(0, $result);
    }

    // ========== Data mapping tests ==========

    #[Test]
    public function testFindByIdMapsAllFieldsCorrectly(): void
    {
        $dbRow = [
            'id' => 42,
            'name' => 'Full Test Key',
            'secret_key' => 'pwa_full_secret',
            'created_by' => 10,
            'created_at' => '2024-06-15 10:30:00',
            'last_used_at' => '2024-06-20 14:45:00',
            'disabled' => 1,
            'expires_at' => '2025-06-15 00:00:00'
        ];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($dbRow);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findById(42);

        $this->assertEquals(42, $result->getId());
        $this->assertEquals('Full Test Key', $result->getName());
        $this->assertEquals('pwa_full_secret', $result->getSecretKey());
        $this->assertEquals(10, $result->getCreatedBy());
        $this->assertTrue($result->isDisabled());
        $this->assertNotNull($result->getLastUsedAt());
        $this->assertNotNull($result->getExpiresAt());
    }

    #[Test]
    public function testFindByIdHandlesNullCreatedBy(): void
    {
        $dbRow = $this->createApiKeyDbRow(['created_by' => null]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($dbRow);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findById(1);

        $this->assertNull($result->getCreatedBy());
    }
}
