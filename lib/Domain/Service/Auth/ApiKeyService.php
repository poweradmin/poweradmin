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

namespace Poweradmin\Domain\Service\Auth;

use DateTime;
use Exception;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Creates, regenerates, disables and deletes API keys and enforces the per-user key limit.
 */
class ApiKeyService
{
    private ApiKeyRepositoryInterface $apiKeyRepository;
    private UserLookupInterface $users;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private ActorInterface $actor;
    private UserContextService $session;
    private PermissionService $permissionService;

    /**
     * ApiKeyService constructor
     *
     * @param ApiKeyRepositoryInterface $apiKeyRepository The API key repository
     * @param UserLookupInterface $users Resolves key creators and owners
     * @param ConfigurationInterface $config The configuration manager
     * @param PermissionService $permissionService Decides who may see and manage other users' keys
     * @param ActorInterface $actor The user the owner checks are about
     * @param UserContextService $session Where a successful key authentication records the user
     */
    public function __construct(
        ApiKeyRepositoryInterface $apiKeyRepository,
        UserLookupInterface $users,
        ConfigurationInterface $config,
        PermissionService $permissionService,
        ActorInterface $actor,
        UserContextService $session,
        ?LoggerInterface $logger = null
    ) {
        $this->apiKeyRepository = $apiKeyRepository;
        $this->users = $users;
        $this->config = $config;
        $this->permissionService = $permissionService;
        $this->logger = $logger ?? new NullLogger();
        $this->actor = $actor;
        $this->session = $session;
    }

    /**
     * Check if the acting user has the given permission (admins always pass)
     */
    private function currentUserHasPermission(string $permission): bool
    {
        $userId = $this->actor->userId();
        if ($userId === null) {
            return false;
        }
        return $this->permissionService->hasPermission($userId, $permission);
    }

    /**
     * Get all API keys the current user has access to with creator usernames
     *
     * @return array Array of API keys with creator usernames
     */
    public function getAllApiKeys(): array
    {
        $userId = $this->actor->userId() ?? 0;

        // Admin users can see all API keys, regular users only see their own
        if ($this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER)) {
            $apiKeys = $this->apiKeyRepository->getAll();
        } else {
            $apiKeys = $this->apiKeyRepository->getAll($userId);
        }

        foreach ($apiKeys as $key) {
            $this->attachCreator($key);
        }

        return $apiKeys;
    }

    /**
     * Get a specific API key if the current user has access to it
     *
     * @param int $id The ID of the API key
     * @return ApiKey|null The API key, or null if not found
     */
    public function getApiKey(int $id): ?ApiKey
    {
        $apiKey = $this->apiKeyRepository->findById($id);

        if ($apiKey === null) {
            return null;
        }

        // Check if the current user has access to this API key
        $userId = $this->actor->userId() ?? 0;
        if ($this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER) || $apiKey->getCreatedBy() === $userId) {
            $this->attachCreator($apiKey);
            $apiKey->setZoneIds($this->apiKeyRepository->getZoneIds($apiKey->getId()));

            return $apiKey;
        }

        return null;
    }

    /**
     * Create a new API key
     *
     * @param string $name The name of the API key
     * @param DateTime|null $expiresAt Optional expiration date
     * @param bool $isReadonly Whether the key is restricted to read-only requests
     * @param string[]|null $allowedOperations Operations the key may perform; null/empty means all
     * @param int[] $zoneIds Zones the key is restricted to; empty means no restriction
     */
    public function createApiKey(string $name, ?DateTime $expiresAt = null, bool $isReadonly = false, ?array $allowedOperations = null, array $zoneIds = []): ApiKeyWriteResult
    {
        $userId = $this->actor->userId() ?? 0;

        if (!$this->config->get('api', 'enabled', false)) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_API_DISABLED, _('API functionality is disabled.'), Refusal::FORBIDDEN);
        }

        if (!$this->canManageKeys()) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_FORBIDDEN, _('You do not have permission to create API keys.'), Refusal::FORBIDDEN);
        }

        $maxKeysPerUser = $this->config->get('api', 'max_keys_per_user', 5);
        if ($this->apiKeyRepository->countByUser($userId) >= $maxKeysPerUser && !$this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER)) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_LIMIT, _('You have reached the maximum number of API keys allowed.'), Refusal::CONFLICT);
        }

        $apiKey = new ApiKey(
            $name,
            ApiKey::generateSecretKey(),
            $userId,
            new DateTime(),
            null,
            false,
            $expiresAt
        );
        $apiKey->setIsReadonly($isReadonly);
        $apiKey->setAllowedOperations($this->sanitizeOperations($allowedOperations));

        $saved = $this->apiKeyRepository->save($apiKey);
        $this->apiKeyRepository->saveZoneIds($saved->getId(), $zoneIds);
        $saved->setZoneIds($zoneIds);

        return ApiKeyWriteResult::ok($saved);
    }

    /**
     * Update an existing API key
     *
     * @param int $id The ID of the API key to update
     * @param string $name The new name for the API key
     * @param DateTime|null $expiresAt The new expiration date
     * @param bool $disabled Whether the API key should be disabled
     * @param bool $isReadonly Whether the key is restricted to read-only requests
     * @param string[]|null $allowedOperations Operations the key may perform; null/empty means all
     * @param int[]|null $zoneIds Zones the key is restricted to; null leaves them unchanged, [] clears
     */
    public function updateApiKey(int $id, string $name, ?DateTime $expiresAt = null, bool $disabled = false, bool $isReadonly = false, ?array $allowedOperations = null, ?array $zoneIds = null): ApiKeyWriteResult
    {
        $apiKey = $this->getApiKey($id);
        if ($apiKey === null) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_NOT_FOUND, _('API key not found or you do not have permission to edit it.'), Refusal::NOT_FOUND);
        }
        if (!$this->canManageKeys()) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_FORBIDDEN, _('You do not have permission to update API keys.'), Refusal::FORBIDDEN);
        }

        $apiKey->setName($name);
        $apiKey->setExpiresAt($expiresAt);
        $apiKey->setDisabled($disabled);
        $apiKey->setIsReadonly($isReadonly);
        $apiKey->setAllowedOperations($this->sanitizeOperations($allowedOperations));

        $saved = $this->apiKeyRepository->save($apiKey);

        // A null zone list means "leave the existing scope untouched"; an empty
        // array clears it. This lets callers that don't manage zones skip them.
        if ($zoneIds !== null) {
            $this->apiKeyRepository->saveZoneIds($id, $zoneIds);
            $saved->setZoneIds($zoneIds);
        }

        return ApiKeyWriteResult::ok($saved);
    }

    /**
     * Delete an API key
     */
    public function deleteApiKey(int $id): ApiKeyWriteResult
    {
        $apiKey = $this->getApiKey($id);
        if ($apiKey === null) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_NOT_FOUND, _('API key not found or you do not have permission to delete it.'), Refusal::NOT_FOUND);
        }
        if (!$this->canManageKeys()) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_FORBIDDEN, _('You do not have permission to delete API keys.'), Refusal::FORBIDDEN);
        }

        return $this->apiKeyRepository->delete($id)
            ? ApiKeyWriteResult::ok($apiKey)
            : ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_WRITE, _('An error occurred. Please try again.'), Refusal::BACKEND_FAILURE);
    }

    /**
     * Regenerate the secret key for an API key
     */
    public function regenerateSecretKey(int $id): ApiKeyWriteResult
    {
        $apiKey = $this->getApiKey($id);
        if ($apiKey === null) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_NOT_FOUND, _('API key not found or you do not have permission to edit it.'), Refusal::NOT_FOUND);
        }
        if (!$this->canManageKeys()) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_FORBIDDEN, _('You do not have permission to regenerate API keys.'), Refusal::FORBIDDEN);
        }

        $apiKey->regenerateSecretKey();

        return ApiKeyWriteResult::ok($this->apiKeyRepository->save($apiKey));
    }

    /**
     * Toggle the disabled status of an API key
     */
    public function toggleApiKey(int $id, bool $disabled): ApiKeyWriteResult
    {
        $apiKey = $this->getApiKey($id);
        if ($apiKey === null) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_NOT_FOUND, _('API key not found or you do not have permission to edit it.'), Refusal::NOT_FOUND);
        }
        if (!$this->canManageKeys()) {
            return ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_FORBIDDEN, _('You do not have permission to update API keys.'), Refusal::FORBIDDEN);
        }

        $apiKey->setDisabled($disabled);

        return ApiKeyWriteResult::ok($this->apiKeyRepository->save($apiKey));
    }

    private function canManageKeys(): bool
    {
        return $this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER) || $this->currentUserHasPermission(Permission::PERM_API_MANAGE_KEYS);
    }

    /**
     * Authenticate a request using an API key
     *
     * @param string $secretKey The secret key from the request
     * @return bool True if authentication succeeded, false otherwise
     */
    public function authenticate(string $secretKey): bool
    {
        if (!$this->config->get('api', 'enabled', false)) {
            return false;
        }

        // The `pwa_` prefix is informational only; the repository looks the key
        // up by hash regardless of prefix, so legacy unprefixed keys still work.
        return $this->authenticateWithKey($secretKey);
    }

    /**
     * Internal method to authenticate with a specific key. The repository hashes
     * the candidate before lookup; legacy plaintext rows are migrated to a hash
     * on first match (see {@see DbApiKeyRepository::findBySecretKey}).
     */
    private function authenticateWithKey(string $secretKey): bool
    {
        $apiKey = $this->resolveUsableKey($secretKey);
        if ($apiKey === null) {
            return false;
        }

        $this->apiKeyRepository->updateLastUsed($apiKey->getId());
        $this->session->setSessionData(SessionKeys::USERID, $apiKey->getCreatedBy());
        $this->session->setSessionData(SessionKeys::AUTH_USED, 'api_key');

        return true;
    }

    /**
     * Get user ID from API key without setting session (stateless).
     */
    public function getUserIdFromApiKey(string $secretKey): int
    {
        if (!$this->config->get('api', 'enabled', false)) {
            return 0;
        }

        try {
            $apiKey = $this->resolveUsableKey($secretKey);
            if ($apiKey === null) {
                return 0;
            }

            $this->apiKeyRepository->updateLastUsed($apiKey->getId());
            return $apiKey->getCreatedBy() ?? 0;
        } catch (Exception $e) {
            $this->logger->error('Failed to get user ID from API key: {error}', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get the api_keys row id from a raw secret key without setting session (stateless).
     * Used for audit logging so a request can be traced back to a specific key.
     */
    public function getIdFromApiKey(string $secretKey): ?int
    {
        if (!$this->config->get('api', 'enabled', false)) {
            return null;
        }

        try {
            $apiKey = $this->resolveUsableKey($secretKey);
            if ($apiKey === null) {
                return null;
            }

            return $apiKey->getId();
        } catch (Exception $e) {
            $this->logger->error('Failed to get id from API key: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Resolve the permission scope of an API key (stateless, no session writes).
     *
     * Returns null when the key is missing/invalid or the API is disabled, in
     * which case the caller should fall back to an unrestricted scope (the key is
     * already rejected by authentication). A key with no restrictions yields a
     * scope where every zone and operation is allowed.
     *
     * @param string $secretKey The raw secret key from the request
     * @return ApiKeyScope|null The resolved scope, or null if it cannot be resolved
     */
    public function getScopeFromApiKey(string $secretKey): ?ApiKeyScope
    {
        if (!$this->config->get('api', 'enabled', false)) {
            return null;
        }

        try {
            $apiKey = $this->resolveUsableKey($secretKey);
            if ($apiKey === null) {
                return null;
            }

            $zoneIds = $this->apiKeyRepository->getZoneIds($apiKey->getId());

            return new ApiKeyScope(
                $zoneIds === [] ? null : $zoneIds,
                $apiKey->getAllowedOperations(),
                $apiKey->isReadonly()
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to resolve API key scope: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Resolve a key only when the key itself is usable and the user it was issued
     * to is still active. Deactivating a user has to revoke their keys: password
     * login already refuses an inactive account, so a key that skipped this check
     * kept the deactivated owner's permissions and zone scope.
     *
     * Fails closed - a key that cannot be attributed to a live user (no
     * created_by, or the row is gone) is rejected rather than let through unowned.
     */
    private function resolveUsableKey(string $secretKey): ?ApiKey
    {
        $apiKey = $this->apiKeyRepository->findBySecretKey($secretKey);
        if ($apiKey === null || !$apiKey->isValid()) {
            return null;
        }

        if (!$this->ownerIsActive($apiKey->getCreatedBy())) {
            $this->logger->warning('API key {id} rejected: owner is inactive or no longer exists', [
                'id' => $apiKey->getId()
            ]);
            return null;
        }

        return $apiKey;
    }

    private function ownerIsActive(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        // Fail closed on a lookup error: authenticate() has no try block of its
        // own, so a throw here would surface as a 500 instead of an auth failure.
        try {
            $row = $this->users->getUserById($userId);
        } catch (Exception $e) {
            $this->logger->error('Failed to verify API key owner: {error}', ['error' => $e->getMessage()]);
            return false;
        }

        return is_array($row) && (int)($row['active'] ?? 0) === 1;
    }

    /**
     * Creator username and fullname, empty when the key has no creator or the account is gone.
     */
    private function attachCreator(ApiKey $key): void
    {
        $user = $key->getCreatedBy() === null ? null : $this->users->getUserById($key->getCreatedBy());

        $key->setCreatorUsername($user ? ($user['username'] ?: '') : '');
        $key->setCreatorFullname($user ? ($user['fullname'] ?: '') : '');
    }

    /**
     * Keep only recognised operation names; an empty/null result means "all".
     *
     * @param string[]|null $operations
     * @return string[]|null
     */
    private function sanitizeOperations(?array $operations): ?array
    {
        if ($operations === null) {
            return null;
        }

        $valid = array_values(array_intersect(ApiKeyScope::OPERATIONS, $operations));

        return $valid === [] ? null : $valid;
    }
}
