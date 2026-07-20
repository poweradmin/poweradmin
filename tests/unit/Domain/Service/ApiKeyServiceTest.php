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

namespace Poweradmin\Tests\Unit\Domain\Service;

use DateTime;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

#[CoversClass(ApiKeyService::class)]
class ApiKeyServiceTest extends TestCase
{
    private ApiKeyService $service;
    private ApiKeyRepositoryInterface&MockObject $apiKeyRepository;
    private PDO&MockObject $db;
    private ConfigurationManager&MockObject $config;
    private MessageService&MockObject $messageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKeyRepository = $this->createMock(ApiKeyRepositoryInterface::class);
        $this->db = $this->createMock(PDO::class);
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

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(false);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);

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

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(false);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);

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

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getCreatedBy')->willReturn(42);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);
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

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getCreatedBy')->willReturn(42);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);
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

        $this->apiKeyRepository->method('findBySecretKey')->willReturn(null);

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

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(false);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);

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

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(false);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);

        $result = $this->service->getUserIdFromApiKey('pwa_expired_key');
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsUserIdForValidKey(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getCreatedBy')->willReturn(42);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);
        $this->apiKeyRepository->expects($this->once())->method('updateLastUsed')->with(1);

        $this->assertEquals(42, $this->service->getUserIdFromApiKey('pwa_valid_key'));
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsUserIdForKeyWithNoExpiration(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getCreatedBy')->willReturn(42);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);

        $this->assertEquals(42, $this->service->getUserIdFromApiKey('pwa_valid_key_no_expiry'));
    }

    #[Test]
    public function testAuthenticateWithNonPrefixedKey(): void
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getCreatedBy')->willReturn(42);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);
        $this->apiKeyRepository->expects($this->once())
            ->method('updateLastUsed')
            ->with(1);

        // Test with a key that doesn't start with 'pwa_'
        $result = $this->service->authenticate('legacy_api_key_format');
        $this->assertTrue($result);
    }

    /**
     * Point the PDO mock's permission queries at exactly the given permission set.
     * The admin-check query (filtered on 'user_is_ueberuser') and the full permission
     * list query are answered separately, matching DbUserRepository's two queries.
     *
     * @param string[] $permissions Permission names the logged-in user holds.
     */
    private function grantPermissions(array $permissions): void
    {
        $this->db->method('prepare')->willReturnCallback(function (string $query) use ($permissions) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);

            if (str_contains($query, "= 'user_is_ueberuser'")) {
                $isAdmin = in_array('user_is_ueberuser', $permissions, true);
                $stmt->method('fetch')->willReturn($isAdmin ? ['permission' => 'user_is_ueberuser'] : false);
            } else {
                $rows = array_map(static fn($name) => ['permission' => $name], $permissions);
                $rows[] = false;
                $stmt->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
            }

            return $stmt;
        });

        $_SESSION['userid'] = 7;
    }

    #[Test]
    public function testCreateApiKeyDeniedWithoutPermission(): void
    {
        $this->config->method('get')->willReturnMap([
            ['api', 'enabled', false, true],
            ['api', 'max_keys_per_user', 5, 5],
        ]);

        // User holds neither user_is_ueberuser nor api_manage_keys
        $this->grantPermissions(['zone_content_view_own']);

        $this->messageService->expects($this->once())->method('addSystemError');
        $this->apiKeyRepository->expects($this->never())->method('save');

        $this->assertNull($this->service->createApiKey('my-key'));
    }

    #[Test]
    public function testCreateApiKeyAllowedForAdmin(): void
    {
        $this->config->method('get')->willReturnMap([
            ['api', 'enabled', false, true],
            ['api', 'max_keys_per_user', 5, 5],
        ]);

        // Admins bypass both the permission gate and the per-user key limit
        $this->grantPermissions(['user_is_ueberuser']);

        $saved = $this->createMock(ApiKey::class);
        $saved->method('getId')->willReturn(99);
        $this->apiKeyRepository->expects($this->once())->method('save')->willReturn($saved);
        $this->apiKeyRepository->expects($this->once())->method('saveZoneIds')->with(99, []);

        $this->assertSame($saved, $this->service->createApiKey('my-key'));
    }
}
