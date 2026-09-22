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

namespace Poweradmin\Tests\Unit\Domain\Service\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Service\Auth\ApiKeyService;
use Poweradmin\Domain\Service\Auth\ApiKeyWriteResult;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Infrastructure\Session\ArraySession;

#[CoversClass(ApiKeyService::class)]
class ApiKeyServiceTest extends PermissionServiceTestCase
{
    private ArraySession $session;

    private ApiKeyService $service;
    private ApiKeyRepositoryInterface&MockObject $apiKeyRepository;
    private UserLookupInterface&MockObject $users;
    private FakeConfiguration $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new ArraySession();

        $this->apiKeyRepository = $this->createMock(ApiKeyRepositoryInterface::class);
        $this->users = $this->createMock(UserLookupInterface::class);
        $this->configureApi([]);
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenApiDisabled(): void
    {
        $this->configureApi(['enabled' => false]);

        $result = $this->service->authenticate('pwa_test_key');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenKeyNotFound(): void
    {
        $this->configureApi(['enabled' => true]);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn(null);

        $result = $this->service->authenticate('pwa_invalid_key');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenKeyIsDisabled(): void
    {
        $this->configureApi(['enabled' => true]);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(false);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);

        $result = $this->service->authenticate('pwa_disabled_key');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAuthenticateReturnsFalseWhenKeyIsExpired(): void
    {
        $this->configureApi(['enabled' => true]);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(false);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);

        $result = $this->service->authenticate('pwa_expired_key');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAuthenticateReturnsTrueForValidKey(): void
    {
        $this->configureApi(['enabled' => true]);

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
        $this->assertEquals(42, $this->session->get('userid'));
        $this->assertEquals('api_key', $this->session->get('auth_used'));
    }

    #[Test]
    public function testAuthenticateReturnsTrueForValidKeyWithNoExpiration(): void
    {
        $this->configureApi(['enabled' => true]);

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
        $this->configureApi(['enabled' => false]);

        $result = $this->service->getUserIdFromApiKey('pwa_test_key');
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsZeroWhenKeyNotFound(): void
    {
        $this->configureApi(['enabled' => true]);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn(null);

        $result = $this->service->getUserIdFromApiKey('pwa_invalid_key');
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsZeroWhenKeyIsDisabled(): void
    {
        $this->configureApi(['enabled' => true]);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(false);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);

        $result = $this->service->getUserIdFromApiKey('pwa_disabled_key');
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsZeroWhenKeyIsExpired(): void
    {
        $this->configureApi(['enabled' => true]);

        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isValid')->willReturn(false);

        $this->apiKeyRepository->method('findBySecretKey')->willReturn($apiKey);

        $result = $this->service->getUserIdFromApiKey('pwa_expired_key');
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function testGetUserIdFromApiKeyReturnsUserIdForValidKey(): void
    {
        $this->configureApi(['enabled' => true]);

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
        $this->configureApi(['enabled' => true]);

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
        $this->configureApi(['enabled' => true]);

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
     * Answer the owner lookup that every key resolution performs.
     * Pass false to model a created_by pointing at a row that no longer exists.
     */
    private function mockOwnerRow(array|false $row): void
    {
        $this->users->method('getUserById')->with(42)->willReturn($row === false ? null : $row);
    }

    private function validKeyOwnedBy(?int $ownerId): ApiKey&MockObject
    {
        $this->configureApi(['enabled' => true]);

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
        $this->assertFalse($this->session->has('userid'));
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

    private function configureApi(array $api): void
    {
        $this->config = new FakeConfiguration(['api' => $api]);
        $this->service = new ApiKeyService(
            $this->apiKeyRepository,
            $this->users,
            $this->config,
            $this->createMock(PermissionService::class),
            StubActor::nobody(),
            new UserContextService($this->session)
        );
    }

    /**
     * Rebuild the service acting as user 7 with exactly the given permissions; the
     * creator lookup (username/fullname) answers with $creatorRow.
     *
     * @param string[] $permissions Permission names the logged-in user holds.
     * @param array|false $creatorRow Row returned for the creator lookup; false means user not found.
     */
    private function grantPermissions(array $permissions, array|false $creatorRow = false): void
    {
        $this->users->method('getUserById')->willReturn($creatorRow === false ? null : $creatorRow);

        $isAdmin = in_array(Permission::PERM_USER_IS_UEBERUSER, $permissions, true);
        $this->service = new ApiKeyService(
            $this->apiKeyRepository,
            $this->users,
            $this->config,
            $this->buildPermissionService(permissionsByUser: [7 => $permissions], adminUserIds: $isAdmin ? [7] : []),
            new StubActor(7),
            new UserContextService($this->session)
        );
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
    public function testGetAllApiKeysAttachesCreatorsAndLeavesOrphansEmpty(): void
    {
        $this->grantPermissions([Permission::PERM_USER_IS_UEBERUSER], ['username' => 'alice', 'fullname' => 'Alice Admin']);

        $owned = $this->createMock(ApiKey::class);
        $owned->method('getCreatedBy')->willReturn(7);
        $owned->expects($this->once())->method('setCreatorUsername')->with('alice');
        $owned->expects($this->once())->method('setCreatorFullname')->with('Alice Admin');

        $orphan = $this->createMock(ApiKey::class);
        $orphan->method('getCreatedBy')->willReturn(null);
        $orphan->expects($this->once())->method('setCreatorUsername')->with('');
        $orphan->expects($this->once())->method('setCreatorFullname')->with('');

        $this->apiKeyRepository->expects($this->once())->method('getAll')->with(null)->willReturn([$owned, $orphan]);

        $this->assertSame([$owned, $orphan], $this->service->getAllApiKeys());
    }

    #[Test]
    public function testAuthenticateFailsClosedWhenTheOwnerLookupThrows(): void
    {
        $this->validKeyOwnedBy(42);
        $this->users->method('getUserById')->willThrowException(new \RuntimeException('db gone'));
        $this->apiKeyRepository->expects($this->never())->method('updateLastUsed');

        $this->assertFalse($this->service->authenticate('pwa_valid_key'));
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
        $this->configureApi(['enabled' => true, 'max_keys_per_user' => 5]);

        // User holds neither user_is_ueberuser nor api_manage_keys
        $this->grantPermissions([Permission::PERM_ZONE_CONTENT_VIEW_OWN]);

        $this->apiKeyRepository->expects($this->never())->method('save');

        $result = $this->service->createApiKey('my-key');

        $this->assertFalse($result->success);
        $this->assertSame(ApiKeyWriteResult::ERR_FORBIDDEN, $result->code);
        $this->assertSame(Refusal::FORBIDDEN, $result->refusal);
    }

    #[Test]
    public function testCreateApiKeyAllowedForAdmin(): void
    {
        $this->configureApi(['enabled' => true, 'max_keys_per_user' => 5]);

        // Admins bypass both the permission gate and the per-user key limit
        $this->grantPermissions([Permission::PERM_USER_IS_UEBERUSER]);

        $saved = $this->createMock(ApiKey::class);
        $saved->method('getId')->willReturn(99);
        $this->apiKeyRepository->expects($this->once())->method('save')->willReturn($saved);
        $this->apiKeyRepository->expects($this->once())->method('saveZoneIds')->with(99, []);

        $this->assertSame($saved, $this->service->createApiKey('my-key')->key);
    }
}
