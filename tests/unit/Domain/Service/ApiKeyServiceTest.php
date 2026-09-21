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

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Domain\Service\ApiKeyWriteResult;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\PermissionServiceTestCase;

#[CoversClass(ApiKeyService::class)]
class ApiKeyServiceTest extends PermissionServiceTestCase
{
    private ApiKeyService $service;
    private ApiKeyRepositoryInterface&MockObject $apiKeyRepository;
    private PDO&MockObject $db;
    private ConfigurationManager&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKeyRepository = $this->createMock(ApiKeyRepositoryInterface::class);
        $this->db = $this->createMock(PDO::class);
        $this->config = $this->createMock(ConfigurationManager::class);

        $this->service = new ApiKeyService(
            $this->apiKeyRepository,
            $this->db,
            $this->config,
            $this->createMock(PermissionService::class)
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

        $this->mockOwnerRow(['active' => 1]);
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

        $this->mockOwnerRow(['active' => 1]);
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

        $this->mockOwnerRow(['active' => 1]);
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

        $this->mockOwnerRow(['active' => 1]);
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

        $this->mockOwnerRow(['active' => 1]);
        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);
        $this->apiKeyRepository->expects($this->once())
            ->method('updateLastUsed')
            ->with(1);

        // Test with a key that doesn't start with 'pwa_'
        $result = $this->service->authenticate('legacy_api_key_format');
        $this->assertTrue($result);
    }

    /**
     * Answer the owner-active lookup that every key resolution now performs.
     * Pass false to model a created_by pointing at a row that no longer exists.
     */
    private function mockOwnerRow(array|false $row): void
    {
        $this->db->method('prepare')->willReturnCallback(function () use ($row) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn($row);
            return $stmt;
        });
    }

    private function validKeyOwnedBy(?int $ownerId): ApiKey&MockObject
    {
        $this->config->method('get')
            ->willReturnMap([
                ['api', 'enabled', false, true],
            ]);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getCreatedBy')->willReturn($ownerId);
        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);

        return $apiKey;
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenOwnerIsDeactivated(): void
    {
        $this->validKeyOwnedBy(42);
        $this->mockOwnerRow(['active' => 0]);
        $this->apiKeyRepository->expects($this->never())->method('updateLastUsed');

        $this->assertFalse($this->service->authenticate('pwa_valid_key'));
        $this->assertArrayNotHasKey('userid', $_SESSION);
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenOwnerRowIsMissing(): void
    {
        // created_by points at a deleted user: unattributable, so it fails closed.
        $this->validKeyOwnedBy(42);
        $this->mockOwnerRow(false);

        $this->assertFalse($this->service->authenticate('pwa_valid_key'));
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenKeyHasNoOwner(): void
    {
        $this->validKeyOwnedBy(null);

        $this->assertFalse($this->service->authenticate('pwa_orphan_key'));
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsZeroWhenOwnerIsDeactivated(): void
    {
        $this->validKeyOwnedBy(42);
        $this->mockOwnerRow(['active' => 0]);

        $this->assertEquals(0, $this->service->getUserIdFromApiKey('pwa_valid_key'));
    }

    #[Test]
    public function testGetScopeFromApiKeyReturnsNullWhenOwnerIsDeactivated(): void
    {
        $this->validKeyOwnedBy(42);
        $this->mockOwnerRow(['active' => 0]);
        $this->apiKeyRepository->expects($this->never())->method('getZoneIds');

        $this->assertNull($this->service->getScopeFromApiKey('pwa_valid_key'));
    }

    #[Test]
    public function testGetIdFromApiKeyReturnsNullWhenOwnerIsDeactivated(): void
    {
        $this->validKeyOwnedBy(42);
        $this->mockOwnerRow(['active' => 0]);

        $this->assertNull($this->service->getIdFromApiKey('pwa_valid_key'));
    }

    /**
     * Rebuild the service so user 7 holds exactly the given permissions; the
     * creator-lookup query (username/fullname) answers with $creatorRow.
     *
     * @param string[] $permissions Permission names the logged-in user holds.
     * @param array|false $creatorRow Row returned for the creator lookup; false means user not found.
     */
    private function grantPermissions(array $permissions, array|false $creatorRow = false): void
    {
        $this->db->method('prepare')->willReturnCallback(function () use ($creatorRow) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn($creatorRow);

            return $stmt;
        });

        $isAdmin = in_array(Permission::PERM_USER_IS_UEBERUSER, $permissions, true);
        $this->service = new ApiKeyService(
            $this->apiKeyRepository,
            $this->db,
            $this->config,
            $this->buildPermissionService(permissionsByUser: [7 => $permissions], adminUserIds: $isAdmin ? [7] : [])
        );

        $_SESSION['userid'] = 7;
    }

    #[Test]
    public function testGetApiKeyPopulatesCreatorDetailsForOwner(): void
    {
        $this->grantPermissions([], ['username' => 'alice', 'fullname' => 'Alice Admin']);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('getId')->willReturn(5);
        $apiKey->method('getCreatedBy')->willReturn(7);
        $apiKey->expects($this->once())->method('setCreatorUsername')->with('alice');
        $apiKey->expects($this->once())->method('setCreatorFullname')->with('Alice Admin');
        $apiKey->expects($this->once())->method('setZoneIds')->with([3, 4]);

        $this->apiKeyRepository->method('findById')->with(5)->willReturn($apiKey);
        $this->apiKeyRepository->method('getZoneIds')->with(5)->willReturn([3, 4]);

        $this->assertSame($apiKey, $this->service->getApiKey(5));
    }

    #[Test]
    public function testGetApiKeyLeavesCreatorEmptyWhenUserNoLongerExists(): void
    {
        // Creator row missing (deleted user): fields fall back to '' and the UI shows "Unknown"
        $this->grantPermissions([]);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('getId')->willReturn(5);
        $apiKey->method('getCreatedBy')->willReturn(7);
        $apiKey->expects($this->once())->method('setCreatorUsername')->with('');
        $apiKey->expects($this->once())->method('setCreatorFullname')->with('');

        $this->apiKeyRepository->method('findById')->with(5)->willReturn($apiKey);
        $this->apiKeyRepository->method('getZoneIds')->with(5)->willReturn([]);

        $this->assertSame($apiKey, $this->service->getApiKey(5));
    }

    #[Test]
    public function testGetApiKeyDeniedForAnotherUsersKey(): void
    {
        $this->grantPermissions([]);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('getCreatedBy')->willReturn(8);

        $this->apiKeyRepository->method('findById')->with(5)->willReturn($apiKey);

        $this->assertNull($this->service->getApiKey(5));
    }

    #[Test]
    public function testCreateApiKeyDeniedWithoutPermission(): void
    {
        $this->config->method('get')->willReturnMap([
            ['api', 'enabled', false, true],
            ['api', 'max_keys_per_user', 5, 5],
        ]);

        // User holds neither user_is_ueberuser nor api_manage_keys
        $this->grantPermissions([Permission::PERM_ZONE_CONTENT_VIEW_OWN]);

        $this->apiKeyRepository->expects($this->never())->method('save');

        $result = $this->service->createApiKey('my-key');

        $this->assertFalse($result->success);
        $this->assertSame(ApiKeyWriteResult::ERR_FORBIDDEN, $result->code);
        $this->assertSame(403, $result->status);
    }

    #[Test]
    public function testCreateApiKeyAllowedForAdmin(): void
    {
        $this->config->method('get')->willReturnMap([
            ['api', 'enabled', false, true],
            ['api', 'max_keys_per_user', 5, 5],
        ]);

        // Admins bypass both the permission gate and the per-user key limit
        $this->grantPermissions([Permission::PERM_USER_IS_UEBERUSER]);

        $saved = $this->createMock(ApiKey::class);
        $saved->method('getId')->willReturn(99);
        $this->apiKeyRepository->expects($this->once())->method('save')->willReturn($saved);
        $this->apiKeyRepository->expects($this->once())->method('saveZoneIds')->with(99, []);

        $this->assertSame($saved, $this->service->createApiKey('my-key')->key);
    }
}
