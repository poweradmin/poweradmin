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

namespace Poweradmin\Application\Controller;

use DateTime;
use Exception;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;

/**
 * Controller for managing API keys
 *
 * @package Poweradmin\Application\Controller
 */
class ApiKeysController extends BaseController
{
    private ApiKeyService $apiKeyService;
    private ApiKeyRepositoryInterface $apiKeyRepository;

    /**
     * Constructor
     *
     * @param array $request Request parameters
     */
    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->apiKeyRepository = new DbApiKeyRepository($this->db, $this->config);
        $this->apiKeyService = new ApiKeyService(
            $this->apiKeyRepository,
            $this->db,
            $this->config,
            $this->messageService
        );
    }

    /**
     * Run the controller
     */
    public function run(): void
    {
        // Check if API is enabled in the config
        if (!$this->config->get('api', 'enabled', false)) {
            $this->showError(_('The API feature is disabled in the system configuration.'));
            return;
        }

        // Only allow ueberuser to manage API keys for now
        if (!UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
            $this->showError(_('You do not have permission to manage API keys. Only administrators can access this feature.'));
            return;
        }

        // Process the action
        $action = $this->getSafeRequestValue('action') ?: 'list';

        switch ($action) {
            case 'list':
                $this->listApiKeys();
                break;
            case 'add':
                $this->addApiKey();
                break;
            case 'edit':
                $this->editApiKey();
                break;
            case 'delete':
                $this->deleteApiKey();
                break;
            case 'regenerate':
                $this->regenerateSecretKey();
                break;
            case 'toggle':
                $this->toggleApiKey();
                break;
            default:
                $this->listApiKeys();
                break;
        }
    }

    /**
     * Show the list of API keys
     */
    private function listApiKeys(): void
    {
        $apiKeys = $this->apiKeyService->getAllApiKeys();

        $this->render('api_keys.html', [
            'api_keys' => $apiKeys,
            'max_keys_per_user' => $this->config->get('api', 'max_keys_per_user', 5),
            'current_keys_count' => $this->apiKeyRepository->countByUser($_SESSION['userid']),
            'can_add_more' => UserManager::verifyPermission($this->db, 'user_is_ueberuser') ||
                $this->apiKeyRepository->countByUser($_SESSION['userid']) < $this->config->get('api', 'max_keys_per_user', 5)
        ]);
    }

    /**
     * Add a new API key
     */
    private function addApiKey(): void
    {
        // Handle form submission
        if ($this->isPost()) {
            $this->validateCsrfToken();

            // Process form data
            $name = $this->getSafeRequestValue('name');
            $expiresAt = $this->getSafeRequestValue('expires_at');

            // Validate form data
            if (empty($name)) {
                $this->showError(_('API key name is required.'));
                return;
            }

            // Parse expiration date if provided
            $expiresAtDate = null;
            if (!empty($expiresAt)) {
                try {
                    $expiresAtDate = new DateTime($expiresAt);
                } catch (Exception $e) {
                    $this->showError(_('Invalid expiration date format.'));
                    return;
                }
            }

            // Create the API key
            $apiKey = $this->apiKeyService->createApiKey($name, $expiresAtDate);

            if ($apiKey !== null) {
                // Show confirmation with the secret key - user needs to save it
                $this->render('api_key_created.html', [
                    'api_key' => $apiKey
                ]);
                return;
            }

            // Error occurred, it was already added to the message service by the API key service
        }

        // Show the add form
        $this->render('api_key_add.html', []);
    }

    /**
     * Edit an existing API key
     */
    private function editApiKey(): void
    {
        $id = (int)$this->getSafeRequestValue('id');

        // Get the API key
        $apiKey = $this->apiKeyService->getApiKey($id);

        if ($apiKey === null) {
            $this->showError(_('API key not found or you do not have permission to edit it.'));
            return;
        }

        // Handle form submission
        if ($this->isPost()) {
            $this->validateCsrfToken();

            // Process form data
            $name = $this->getSafeRequestValue('name');
            $expiresAt = $this->getSafeRequestValue('expires_at');
            $disabled = $this->getSafeRequestValue('disabled') === 'on';

            // Validate form data
            if (empty($name)) {
                $this->showError(_('API key name is required.'));
                return;
            }

            // Parse expiration date if provided
            $expiresAtDate = null;
            if (!empty($expiresAt)) {
                try {
                    $expiresAtDate = new DateTime($expiresAt);
                } catch (Exception $e) {
                    $this->showError(_('Invalid expiration date format.'));
                    return;
                }
            }

            // Update the API key
            $apiKey = $this->apiKeyService->updateApiKey($id, $name, $expiresAtDate, $disabled);

            if ($apiKey !== null) {
                $this->messageService->addMessage('api_keys', 'success', _('API key updated successfully.'));
                $this->redirect('index.php?page=api_keys');
                return;
            }

            // Error occurred, it was already added to the message service by the API key service
        }

        // Show the edit form
        $this->render('api_key_edit.html', [
            'api_key' => $apiKey
        ]);
    }

    /**
     * Delete an API key
     */
    private function deleteApiKey(): void
    {
        $id = (int)$this->getSafeRequestValue('id');

        // Handle form submission for confirmation
        if ($this->isPost()) {
            $this->validateCsrfToken();

            // Delete the API key
            $success = $this->apiKeyService->deleteApiKey($id);

            if ($success) {
                $this->messageService->addMessage('api_keys', 'success', _('API key deleted successfully.'));
            }

            $this->redirect('index.php?page=api_keys');
            return;
        }

        // Get the API key
        $apiKey = $this->apiKeyService->getApiKey($id);

        if ($apiKey === null) {
            $this->showError(_('API key not found or you do not have permission to delete it.'));
            return;
        }

        // Show the delete confirmation
        $this->render('api_key_delete.html', [
            'api_key' => $apiKey
        ]);
    }

    /**
     * Regenerate the secret key for an API key
     */
    private function regenerateSecretKey(): void
    {
        $id = (int)$this->getSafeRequestValue('id');

        // Handle form submission for confirmation
        if ($this->isPost()) {
            $this->validateCsrfToken();

            // Regenerate the secret key
            $apiKey = $this->apiKeyService->regenerateSecretKey($id);

            if ($apiKey !== null) {
                // Show confirmation with the new secret key
                $this->render('api_key_regenerated.html', [
                    'api_key' => $apiKey
                ]);
                return;
            }

            // Error occurred, it was already added to the message service by the API key service
        }

        // Get the API key
        $apiKey = $this->apiKeyService->getApiKey($id);

        if ($apiKey === null) {
            $this->showError(_('API key not found or you do not have permission to edit it.'));
            return;
        }

        // Show the regenerate confirmation
        $this->render('api_key_regenerate.html', [
            'api_key' => $apiKey
        ]);
    }

    /**
     * Toggle the disabled status of an API key
     */
    private function toggleApiKey(): void
    {
        $id = (int)$this->getSafeRequestValue('id');
        $disable = $this->getSafeRequestValue('disable') === '1';

        // Toggle the API key status
        $apiKey = $this->apiKeyService->toggleApiKey($id, $disable);

        if ($apiKey !== null) {
            $status = $disable ? _('disabled') : _('enabled');
            $this->messageService->addMessage('api_keys', 'success', sprintf(_('API key %s successfully.'), $status));
        }

        $this->redirect('index.php?page=api_keys');
    }
}
