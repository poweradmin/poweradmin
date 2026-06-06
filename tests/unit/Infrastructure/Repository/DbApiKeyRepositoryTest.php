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

use DateTime;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;

#[CoversClass(DbApiKeyRepository::class)]
class DbApiKeyRepositoryTest extends TestCase
{
    private DbApiKeyRepository $repository;
    private PDO&MockObject $db;
    private ConfigurationManager&MockObject $config;
    private string $originalErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress error_log output during tests
        $this->originalErrorLog = ini_get('error_log') ?: '';
        ini_set('error_log', '/dev/null');

        $this->db = $this->createMock(PDO::class);
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
        // delete() prepares two statements: the api_key_zones child cleanup and
        // the api_keys row delete. Both share this mock.
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);

        $this->db->method('prepare')->willReturn($stmt);

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
        // Hashed secret_key is intentionally not exposed on the in-memory model;
        // raw key is only available at create/regenerate time.
        $this->assertSame('', $result->getSecretKey());
        $this->assertEquals(10, $result->getCreatedBy());
        $this->assertTrue($result->isDisabled());
        $this->assertNotNull($result->getLastUsedAt());
        $this->assertNotNull($result->getExpiresAt());
    }

    // ========== findBySecretKey tests ==========

    #[Test]
    public function testFindBySecretKeyReturnsApiKeyOnExactMatch(): void
    {
        $secret = 'pwa_match_secret_key_abcdef';

        $hashedLookup = $this->createMock(PDOStatement::class);
        $hashedLookup->method('execute')->willReturn(true);
        $hashedLookup->method('fetch')->willReturn($this->createApiKeyDbRow([
            'id' => 7,
            'name' => 'Match',
            'secret_key' => DbApiKeyRepository::hashSecretKey($secret),
        ]));

        $this->db->method('prepare')->willReturn($hashedLookup);

        $result = $this->repository->findBySecretKey($secret);

        $this->assertInstanceOf(ApiKey::class, $result);
        $this->assertSame(7, $result->getId());
    }

    #[Test]
    public function testFindBySecretKeyRejectsSameLengthWrongSecret(): void
    {
        // Both the hashed lookup and the legacy plaintext fallback return null.
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $this->assertNull($this->repository->findBySecretKey('pwa_match_secret_key_abcdeg'));
    }

    #[Test]
    public function testFindBySecretKeyMigratesLegacyPlaintextRow(): void
    {
        $plaintext = 'pwa_legacy_plaintext_key';

        $hashedLookup = $this->createMock(PDOStatement::class);
        $hashedLookup->method('execute')->willReturn(true);
        $hashedLookup->method('fetch')->willReturn(false);

        $plaintextLookup = $this->createMock(PDOStatement::class);
        $plaintextLookup->method('execute')->willReturn(true);
        $plaintextLookup->method('fetch')->willReturn($this->createApiKeyDbRow([
            'id' => 11,
            'name' => 'Legacy',
            'secret_key' => $plaintext,
        ]));

        $migrate = $this->createMock(PDOStatement::class);
        $migrate->expects($this->once())->method('execute')->willReturn(true);

        $this->db->method('prepare')->willReturnOnConsecutiveCalls(
            $hashedLookup,
            $plaintextLookup,
            $migrate
        );

        $result = $this->repository->findBySecretKey($plaintext);

        $this->assertInstanceOf(ApiKey::class, $result);
        $this->assertSame(11, $result->getId());
    }

    #[Test]
    public function testFindBySecretKeyRejectsSubmittedHashAsCandidate(): void
    {
        // Regression test: an attacker who reads the hashed `secret_key` column
        // (DB backup, read-only SQL injection, etc.) must NOT be able to submit
        // the stored value as a candidate API key. The `sha256$` prefix on the
        // stored format is the discriminator the fallback uses to refuse such
        // candidates before the plaintext SELECT/UPDATE pair can fire.
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        // Only the initial hashed lookup runs. The plaintext SELECT and migrate
        // UPDATE must not be prepared because the prefix gate trips first.
        $this->db->expects($this->once())->method('prepare')->willReturn($stmt);

        $storedHash = DbApiKeyRepository::hashSecretKey('pwa_real_key_value');
        $this->assertNull($this->repository->findBySecretKey($storedHash));
    }

    #[Test]
    public function testHashSecretKeyProducesPrefixedFormat(): void
    {
        $hash = DbApiKeyRepository::hashSecretKey('pwa_example');
        $this->assertStringStartsWith('sha256$', $hash);
        $this->assertSame(7 + 64, strlen($hash));
    }

    #[Test]
    public function testFindBySecretKeyAcceptsLegacy64HexPlaintextKey(): void
    {
        // A legacy plaintext key that happens to be exactly 64 lowercase hex chars
        // (which the previous hash-shape gate would have rejected) still works,
        // because the prefix-based gate is shape-distinctive instead of length-
        // distinctive. The `sha256$` prefix is what proves a value is hashed.
        $plaintext = str_repeat('a', 64);

        $hashedLookup = $this->createMock(PDOStatement::class);
        $hashedLookup->method('execute')->willReturn(true);
        $hashedLookup->method('fetch')->willReturn(false);

        $plaintextLookup = $this->createMock(PDOStatement::class);
        $plaintextLookup->method('execute')->willReturn(true);
        $plaintextLookup->method('fetch')->willReturn($this->createApiKeyDbRow([
            'id' => 99,
            'name' => '64-hex legacy',
            'secret_key' => $plaintext,
        ]));

        $migrate = $this->createMock(PDOStatement::class);
        $migrate->expects($this->once())->method('execute')->willReturn(true);

        $this->db->method('prepare')->willReturnOnConsecutiveCalls(
            $hashedLookup,
            $plaintextLookup,
            $migrate
        );

        $result = $this->repository->findBySecretKey($plaintext);
        $this->assertInstanceOf(ApiKey::class, $result);
        $this->assertSame(99, $result->getId());
    }

    #[Test]
    public function testFindBySecretKeyAllowsLegacyUnprefixedPlaintextKey(): void
    {
        // Operators may have plaintext keys that pre-date the `pwa_` generator
        // format (manually inserted, imported from another tool). These keys
        // should still authenticate and migrate to a hash on first use, as long
        // as they don't accidentally collide with the 64-char-lowercase-hex shape
        // of a SHA-256 hash.
        $plaintext = 'legacy_custom_api_key_format';

        $hashedLookup = $this->createMock(PDOStatement::class);
        $hashedLookup->method('execute')->willReturn(true);
        $hashedLookup->method('fetch')->willReturn(false);

        $plaintextLookup = $this->createMock(PDOStatement::class);
        $plaintextLookup->method('execute')->willReturn(true);
        $plaintextLookup->method('fetch')->willReturn($this->createApiKeyDbRow([
            'id' => 33,
            'name' => 'Legacy Custom',
            'secret_key' => $plaintext,
        ]));

        $migrate = $this->createMock(PDOStatement::class);
        $migrate->expects($this->once())->method('execute')->willReturn(true);

        $this->db->method('prepare')->willReturnOnConsecutiveCalls(
            $hashedLookup,
            $plaintextLookup,
            $migrate
        );

        $result = $this->repository->findBySecretKey($plaintext);
        $this->assertInstanceOf(ApiKey::class, $result);
        $this->assertSame(33, $result->getId());
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
