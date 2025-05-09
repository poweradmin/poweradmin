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

namespace Poweradmin\Domain\Service;

use DateTime;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Service for managing API keys
 *
 * @package Poweradmin\Domain\Service
 */
class ApiKeyService
{
    private ApiKeyRepositoryInterface $apiKeyRepository;
    private PDOLayer $db;
    private ConfigurationManager $config;
    private MessageService $messageService;

    /**
     * Get the database connection for debugging
     *
     * @return PDOLayer
     */
    public function getDb(): PDOLayer
    {
        return $this->db;
    }

    /**
     * ApiKeyService constructor
     *
     * @param ApiKeyRepositoryInterface $apiKeyRepository The API key repository
     * @param PDOLayer $db The database connection
     * @param ConfigurationManager $config The configuration manager
     * @param MessageService $messageService The message service
     */
    public function __construct(
        ApiKeyRepositoryInterface $apiKeyRepository,
        PDOLayer $db,
        ConfigurationManager $config,
        MessageService $messageService
    ) {
        $this->apiKeyRepository = $apiKeyRepository;
        $this->db = $db;
        $this->config = $config;
        $this->messageService = $messageService;
    }

    /**
     * Get all API keys the current user has access to
     *
     * @return ApiKey[] Array of API keys
     */
    public function getAllApiKeys(): array
    {
        $userId = $_SESSION['userid'] ?? 0;

        // Admin users can see all API keys, regular users only see their own
        if (UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
            return $this->apiKeyRepository->getAll();
        } else {
            return $this->apiKeyRepository->getAll($userId);
        }
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
        $userId = $_SESSION['userid'] ?? 0;
        if (UserManager::verifyPermission($this->db, 'user_is_ueberuser') || $apiKey->getCreatedBy() === $userId) {
            return $apiKey;
        }

        return null;
    }

    /**
     * Create a new API key
     *
     * @param string $name The name of the API key
     * @param DateTime|null $expiresAt Optional expiration date
     * @return ApiKey|null The created API key, or null if creation failed
     */
    public function createApiKey(string $name, ?DateTime $expiresAt = null): ?ApiKey
    {
        $userId = $_SESSION['userid'] ?? 0;

        // Check if API is enabled
        if (!$this->config->get('api', 'enabled', false)) {
            $this->messageService->addSystemError(_('API functionality is disabled.'));
            return null;
        }

        // Check if user has permission to create API keys
        if (!UserManager::verifyPermission($this->db, 'api_manage_keys')) {
            $this->messageService->addSystemError(_('You do not have permission to create API keys.'));
            return null;
        }

        // Check maximum number of API keys per user
        $maxKeysPerUser = $this->config->get('api', 'max_keys_per_user', 5);
        if ($this->apiKeyRepository->countByUser($userId) >= $maxKeysPerUser && !UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
            $this->messageService->addSystemError(_('You have reached the maximum number of API keys allowed.'));
            return null;
        }

        // Create and save the new API key
        $apiKey = new ApiKey(
            $name,
            ApiKey::generateSecretKey(),
            $userId,
            new DateTime(),
            null,
            false,
            $expiresAt
        );

        return $this->apiKeyRepository->save($apiKey);
    }

    /**
     * Update an existing API key
     *
     * @param int $id The ID of the API key to update
     * @param string $name The new name for the API key
     * @param DateTime|null $expiresAt The new expiration date
     * @param bool $disabled Whether the API key should be disabled
     * @return ApiKey|null The updated API key, or null if update failed
     */
    public function updateApiKey(int $id, string $name, ?DateTime $expiresAt = null, bool $disabled = false): ?ApiKey
    {
        $apiKey = $this->getApiKey($id);

        if ($apiKey === null) {
            $this->messageService->addSystemError(_('API key not found or you do not have permission to edit it.'));
            return null;
        }

        // Check if user has permission to update API keys
        if (!UserManager::verifyPermission($this->db, 'api_manage_keys')) {
            $this->messageService->addSystemError(_('You do not have permission to update API keys.'));
            return null;
        }

        // Update the API key
        $apiKey->setName($name);
        $apiKey->setExpiresAt($expiresAt);
        $apiKey->setDisabled($disabled);

        return $this->apiKeyRepository->save($apiKey);
    }

    /**
     * Delete an API key
     *
     * @param int $id The ID of the API key to delete
     * @return bool True if the API key was deleted, false otherwise
     */
    public function deleteApiKey(int $id): bool
    {
        $apiKey = $this->getApiKey($id);

        if ($apiKey === null) {
            $this->messageService->addSystemError(_('API key not found or you do not have permission to delete it.'));
            return false;
        }

        // Check if user has permission to delete API keys
        if (!UserManager::verifyPermission($this->db, 'api_manage_keys')) {
            $this->messageService->addSystemError(_('You do not have permission to delete API keys.'));
            return false;
        }

        return $this->apiKeyRepository->delete($id);
    }

    /**
     * Regenerate the secret key for an API key
     *
     * @param int $id The ID of the API key
     * @return ApiKey|null The updated API key with new secret, or null if regeneration failed
     */
    public function regenerateSecretKey(int $id): ?ApiKey
    {
        $apiKey = $this->getApiKey($id);

        if ($apiKey === null) {
            $this->messageService->addSystemError(_('API key not found or you do not have permission to edit it.'));
            return null;
        }

        // Check if user has permission to update API keys
        if (!UserManager::verifyPermission($this->db, 'api_manage_keys')) {
            $this->messageService->addSystemError(_('You do not have permission to regenerate API keys.'));
            return null;
        }

        // Generate a new secret key
        $apiKey->regenerateSecretKey();

        return $this->apiKeyRepository->save($apiKey);
    }

    /**
     * Toggle the disabled status of an API key
     *
     * @param int $id The ID of the API key
     * @param bool $disabled The new disabled status
     * @return ApiKey|null The updated API key, or null if update failed
     */
    public function toggleApiKey(int $id, bool $disabled): ?ApiKey
    {
        $apiKey = $this->getApiKey($id);

        if ($apiKey === null) {
            $this->messageService->addSystemError(_('API key not found or you do not have permission to edit it.'));
            return null;
        }

        // Check if user has permission to update API keys
        if (!UserManager::verifyPermission($this->db, 'api_manage_keys')) {
            $this->messageService->addSystemError(_('You do not have permission to update API keys.'));
            return null;
        }

        // Update the disabled status
        $apiKey->setDisabled($disabled);

        return $this->apiKeyRepository->save($apiKey);
    }

    /**
     * Authenticate a request using an API key
     *
     * @param string $secretKey The secret key from the request
     * @return bool True if authentication succeeded, false otherwise
     */
    public function authenticate(string $secretKey): bool
    {
        // Check if API is enabled
        if (!$this->config->get('api', 'enabled', false)) {
            error_log('[ApiKeyService] API is disabled in configuration');
            return false;
        }

        // Log exact key info for debugging
        error_log(sprintf(
            '[ApiKeyService] API key received with length: %d, first chars: %s, last chars: %s',
            strlen($secretKey),
            substr($secretKey, 0, 4),
            substr($secretKey, -4)
        ));

        // Try direct DB lookup first for debugging
        try {
            $stmt = $this->db->prepare("SELECT id, name, disabled FROM api_keys WHERE secret_key = ?");
            $stmt->execute([$secretKey]);
            $keyData = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($keyData) {
                error_log(sprintf(
                    '[ApiKeyService] Direct DB query found key ID: %d, Name: %s, Disabled: %s',
                    $keyData['id'],
                    $keyData['name'],
                    $keyData['disabled'] ? 'Yes' : 'No'
                ));
            } else {
                error_log('[ApiKeyService] Direct DB query found no matching key');
            }
        } catch (\Exception $e) {
            error_log('[ApiKeyService] Error with direct query: ' . $e->getMessage());
        }

        // Check for a direct database match using BINARY comparison for exact matching
        try {
            $stmt = $this->db->prepare("SELECT id, name, created_by, disabled, expires_at FROM api_keys WHERE BINARY secret_key = ?");
            $stmt->execute([$secretKey]);
            $keyData = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($keyData) {
                error_log(sprintf(
                    '[ApiKeyService] Found key in database: ID %d, Name %s, User %d',
                    $keyData['id'],
                    $keyData['name'],
                    $keyData['created_by']
                ));

                // Check if key is disabled
                if ((bool)$keyData['disabled']) {
                    error_log('[ApiKeyService] API key is disabled');
                    return false;
                }

                // Check if key is expired
                if ($keyData['expires_at'] && new \DateTime($keyData['expires_at']) < new \DateTime()) {
                    error_log('[ApiKeyService] API key is expired');
                    return false;
                }

                // Set session variables for the authenticated user
                $_SESSION['userid'] = $keyData['created_by'];
                $_SESSION['auth_used'] = 'api_key';

                // Update last used timestamp
                $this->apiKeyRepository->updateLastUsed($keyData['id']);

                return true;
            } else {
                error_log('[ApiKeyService] API key not found in direct database check');
            }
        } catch (\Exception $e) {
            error_log('[ApiKeyService] Error with direct database check: ' . $e->getMessage());
        }

        // If the direct database check failed, try the repository method as fallback
        error_log('[ApiKeyService] Direct DB check failed, trying repository lookup...');
        $apiKey = $this->apiKeyRepository->findBySecretKey($secretKey);

        if ($apiKey === null) {
            error_log('[ApiKeyService] API key not found in database via repository');
            return false;
        }

        if (!$apiKey->isValid()) {
            error_log(sprintf(
                '[ApiKeyService] API key found but invalid. Disabled: %s, Expired: %s',
                $apiKey->isDisabled() ? 'Yes' : 'No',
                ($apiKey->getExpiresAt() && $apiKey->getExpiresAt() < new \DateTime()) ? 'Yes' : 'No'
            ));
            return false;
        }

        // Update the last used timestamp
        $this->apiKeyRepository->updateLastUsed($apiKey->getId());

        // Set session variables for the authenticated user
        $_SESSION['userid'] = $apiKey->getCreatedBy();
        $_SESSION['auth_used'] = 'api_key';

        error_log(sprintf('[ApiKeyService] Authentication successful for user ID: %d via repository', $apiKey->getCreatedBy()));
        return true;
    }
}
