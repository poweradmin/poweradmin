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

namespace Poweradmin\Tests\Unit\Domain\Service;

use DateTime;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\MessageService;

#[CoversClass(ApiKeyService::class)]
class ApiKeyServiceTest extends TestCase
{
    private ApiKeyService $service;
    private ApiKeyRepositoryInterface&MockObject $apiKeyRepository;
    private PDOCommon&MockObject $db;
    private ConfigurationManager&MockObject $config;
    private MessageService&MockObject $messageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKeyRepository = $this->createMock(ApiKeyRepositoryInterface::class);
        $this->db = $this->createMock(PDOCommon::class);
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->messageService = $this->createMock(MessageService::class);

        $this->service = new ApiKeyService(
            $this->apiKeyRepository,
            $this->db,
            $this->config,
            $this->messageService
        );

        // Initialize session
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    #[Test]
    public function testGetDbReturnsDbConnection(): void
    {
        $result = $this->service->getDb();
        $this->assertSame($this->db, $result);
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenApiDisabled(): void
    {
        $this->config->method('get')
            ->with('api', 'enabled', false)
            ->willReturn(false);

        $result = $this->service->authenticate('pwa_test_key');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenKeyNotFound(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn(null);

        $result = $this->service->authenticate('pwa_invalid_key');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenKeyIsDisabled(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 1,
            'name' => 'Test Key',
            'created_by' => 1,
            'disabled' => true,
            'expires_at' => null,
        ]);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->service->authenticate('pwa_disabled_key');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenKeyIsExpired(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 1,
            'name' => 'Test Key',
            'created_by' => 1,
            'disabled' => false,
            'expires_at' => '2020-01-01 00:00:00', // Expired date
        ]);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->service->authenticate('pwa_expired_key');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAuthenticateReturnsTrueForValidKey(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $futureDate = (new DateTime('+1 year'))->format('Y-m-d H:i:s');

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 1,
            'name' => 'Test Key',
            'created_by' => 42,
            'disabled' => false,
            'expires_at' => $futureDate,
        ]);

        $this->db->method('prepare')->willReturn($stmt);

        $this->apiKeyRepository->expects($this->once())
            ->method('updateLastUsed')
            ->with(1);

        $result = $this->service->authenticate('pwa_valid_key');
        $this->assertTrue($result);
        $this->assertEquals(42, $_SESSION['userid']);
        $this->assertEquals('api_key', $_SESSION['auth_used']);
    }

    #[Test]
    public function testAuthenticateReturnsTrueForValidKeyWithNoExpiration(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 1,
            'name' => 'Test Key',
            'created_by' => 42,
            'disabled' => false,
            'expires_at' => null, // No expiration
        ]);

        $this->db->method('prepare')->willReturn($stmt);

        $this->apiKeyRepository->expects($this->once())
            ->method('updateLastUsed')
            ->with(1);

        $result = $this->service->authenticate('pwa_valid_key_no_expiry');
        $this->assertTrue($result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsZeroWhenApiDisabled(): void
    {
        $this->config->method('get')
            ->with('api', 'enabled', false)
            ->willReturn(false);

        $result = $this->service->getUserIdFromApiKey('pwa_test_key');
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsZeroWhenKeyNotFound(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->service->getUserIdFromApiKey('pwa_invalid_key');
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsZeroWhenKeyIsDisabled(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'created_by' => 42,
            'disabled' => true,
            'expires_at' => null,
        ]);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->service->getUserIdFromApiKey('pwa_disabled_key');
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsZeroWhenKeyIsExpired(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'created_by' => 42,
            'disabled' => false,
            'expires_at' => '2020-01-01 00:00:00',
        ]);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->service->getUserIdFromApiKey('pwa_expired_key');
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsUserIdForValidKey(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
                ['database', 'type', 'mysql', 'mysql'],
            ]);

        $futureDate = (new DateTime('+1 year'))->format('Y-m-d H:i:s');

        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetch')->willReturn([
            'created_by' => 42,
            'disabled' => false,
            'expires_at' => $futureDate,
        ]);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($selectStmt, $updateStmt);

        $result = $this->service->getUserIdFromApiKey('pwa_valid_key');
        $this->assertEquals(42, $result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsUserIdForKeyWithNoExpiration(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
                ['database', 'type', 'mysql', 'mysql'],
            ]);

        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetch')->willReturn([
            'created_by' => 42,
            'disabled' => false,
            'expires_at' => null,
        ]);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($selectStmt, $updateStmt);

        $result = $this->service->getUserIdFromApiKey('pwa_valid_key_no_expiry');
        $this->assertEquals(42, $result);
    }

    #[Test]
    public function testAuthenticateFallsBackToRepositoryOnDatabaseException(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        // Make the direct DB query throw an exception
        $this->db->method('prepare')
            ->willThrowException(new \Exception('Database error'));

        // Repository returns null (key not found)
        $this->apiKeyRepository->method('findBySecretKey')
            ->willReturn(null);

        $result = $this->service->authenticate('pwa_test_key');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAuthenticateFallsBackToRepositoryAndSucceeds(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        // Make the direct DB query throw an exception
        $this->db->method('prepare')
            ->willThrowException(new \Exception('Database error'));

        // Create a valid ApiKey mock
        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getCreatedBy')->willReturn(42);

        $this->apiKeyRepository->method('findBySecretKey')
            ->willReturn($apiKey);

        $this->apiKeyRepository->expects($this->once())
            ->method('updateLastUsed')
            ->with(1);

        $result = $this->service->authenticate('pwa_test_key');
        $this->assertTrue($result);
        $this->assertEquals(42, $_SESSION['userid']);
        $this->assertEquals('api_key', $_SESSION['auth_used']);
    }

    #[Test]
    public function testAuthenticateFallsBackToRepositoryButKeyIsInvalid(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        // Make the direct DB query throw an exception
        $this->db->method('prepare')
            ->willThrowException(new \Exception('Database error'));

        // Create an invalid ApiKey mock (disabled or expired)
        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(false);

        $this->apiKeyRepository->method('findBySecretKey')
            ->willReturn($apiKey);

        $result = $this->service->authenticate('pwa_test_key');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAuthenticateWithNonPrefixedKey(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 1,
            'name' => 'Legacy Key',
            'created_by' => 42,
            'disabled' => false,
            'expires_at' => null,
        ]);

        $this->db->method('prepare')->willReturn($stmt);

        $this->apiKeyRepository->expects($this->once())
            ->method('updateLastUsed')
            ->with(1);

        // Test with a key that doesn't start with 'pwa_'
        $result = $this->service->authenticate('legacy_api_key_format');
        $this->assertTrue($result);
    }
}
