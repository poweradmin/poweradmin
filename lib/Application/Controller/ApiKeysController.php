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

namespace Poweradmin\Application\Controller;

use DateTime;
use Exception;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;
use Poweradmin\Domain\Service\SessionKeys;

/**
 * Renders the API key list page and links to the add, edit, delete and regenerate actions.
 */
class ApiKeysController extends BaseController
{
    private ApiKeyService $apiKeyService;
    private ApiKeyRepositoryInterface $apiKeyRepository;
    private ZoneReadRepositoryInterface $zoneRepository;

    /**
     * Matched route name, captured before setCurrentPage() overwrites the 'page' key.
     * The router sets it from the real match after merging GET and POST, so it cannot
     * be spoofed by a query parameter and each action keeps the verbs its route allows.
     */
    private string $routeName;

    /**
     * Constructor
     *
     * @param array $request Request parameters
     */
    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->routeName = (string)($request['page'] ?? '');
        $this->apiKeyRepository = new DbApiKeyRepository($this->db, $this->config);
        $this->apiKeyService = new ApiKeyService($this->apiKeyRepository, $this->db, $this->config);
        $this->zoneRepository = $this->createZoneRepository();
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

        // Allow ueberuser or users with api_manage_keys permission to manage API keys
        if (
            !$this->hasPermission('user_is_ueberuser') &&
            !$this->hasPermission('api_manage_keys')
        ) {
            $this->showError(_('You do not have permission to manage API keys.'));
            return;
        }

        // Set the current page for navigation highlighting
        $this->setCurrentPage('api_keys');
        $this->setPageTitle(_('API Keys'));

        $action = $this->getActionFromRoute($this->routeName) ?? 'list';

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
     * Map route name to action
     */
    private function getActionFromRoute(?string $routeName): ?string
    {
        return match ($routeName) {
            'api_keys_list' => 'list',
            'api_keys_add' => 'add',
            'api_keys_edit' => 'edit',
            'api_keys_delete' => 'delete',
            'api_keys_regenerate' => 'regenerate',
            'api_keys_toggle' => 'toggle',
            default => null,
        };
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
            'current_keys_count' => $this->apiKeyRepository->countByUser($_SESSION[SessionKeys::USERID]),
            'can_add_more' => $this->hasPermission('user_is_ueberuser') ||
                $this->apiKeyRepository->countByUser($_SESSION[SessionKeys::USERID]) < $this->config->get('api', 'max_keys_per_user', 5)
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
            $scope = $this->getScopeInputFromRequest();

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
            $created = $this->apiKeyService->createApiKey(
                $name,
                $expiresAtDate,
                $scope['is_readonly'],
                $scope['operations'],
                $scope['zones']
            );

            if ($created->success) {
                $apiKey = $created->key;
                $this->createAuditService()->logApiKeyCreate((int)$apiKey->getId(), $apiKey->getName());

                // Show confirmation with the secret key - user needs to save it
                $this->render('api_key_created.html', [
                    'api_key' => $apiKey
                ]);
                return;
            }

            $this->messageService->addMessage('api_key_add', 'error', (string)$created->message);
        }

        // Show the add form
        $this->render('api_key_add.html', [
            'available_zones' => $this->getAssignableZones(),
            'available_operations' => ApiKeyScope::OPERATIONS,
        ]);
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
            $scope = $this->getScopeInputFromRequest();

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
            $updated = $this->apiKeyService->updateApiKey(
                $id,
                $name,
                $expiresAtDate,
                $disabled,
                $scope['is_readonly'],
                $scope['operations'],
                $scope['zones']
            );

            if ($updated->success) {
                $this->createAuditService()->logApiKeyEdit($id, $updated->key->getName());

                $this->messageService->addMessage('api_keys', 'success', _('API key updated successfully.'));
                $this->redirect('/settings/api-keys');
                return;
            }

            $this->messageService->addMessage('api_key_edit', 'error', (string)$updated->message);
        }

        // Show the edit form
        $this->render('api_key_edit.html', [
            'api_key' => $apiKey,
            'available_zones' => $this->getAssignableZones(),
            'available_operations' => ApiKeyScope::OPERATIONS,
            'selected_zones' => $apiKey->getZoneIds() ?? [],
            'selected_operations' => $apiKey->getAllowedOperations() ?? [],
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

            $deleted = $this->apiKeyService->deleteApiKey($id);

            if ($deleted->success) {
                $this->createAuditService()->logApiKeyDelete($id, $deleted->key->getName());
                $this->messageService->addMessage('api_keys', 'success', _('API key deleted successfully.'));
            } else {
                $this->messageService->addMessage('api_keys', 'error', (string)$deleted->message);
            }

            $this->redirect('/settings/api-keys');
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

        $apiKey = $this->apiKeyService->getApiKey($id);
        if ($apiKey === null) {
            $this->showError(_('API key not found or you do not have permission to edit it.'));
            return;
        }

        // Handle form submission for confirmation
        if ($this->isPost()) {
            $this->validateCsrfToken();

            $regenerated = $this->apiKeyService->regenerateSecretKey($id);

            if ($regenerated->success) {
                $this->createAuditService()->logApiKeyRegenerate($id, $regenerated->key->getName());

                // Show confirmation with the new secret key
                $this->render('api_key_regenerated.html', [
                    'api_key' => $regenerated->key
                ]);
                return;
            }

            $this->messageService->addMessage('api_key_regenerate', 'error', (string)$regenerated->message);
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
        $this->validateCsrfToken();

        $id = (int)$this->getSafeRequestValue('id');
        $disable = $this->getSafeRequestValue('disable') === '1';

        // Toggle the API key status
        $toggled = $this->apiKeyService->toggleApiKey($id, $disable);

        if ($toggled->success) {
            $this->createAuditService()->logApiKeyToggle($id, $toggled->key->getName(), $disable);

            $translatedStatus = $disable ? _('disabled') : _('enabled');
            $this->messageService->addMessage('api_keys', 'success', sprintf(_('API key %s successfully.'), $translatedStatus));
        } else {
            $this->messageService->addMessage('api_keys', 'error', (string)$toggled->message);
        }

        $this->redirect('/settings/api-keys');
    }

    /**
     * Read the optional scope fields (read-only, operations, zones) from the form.
     *
     * @return array{is_readonly: bool, operations: string[]|null, zones: int[]}
     */
    private function getScopeInputFromRequest(): array
    {
        // Only extract raw form values here; ApiKeyService sanitizes the operation
        // list and the model/repository normalize the zone IDs on persistence.
        $operations = $this->requestData['operations'] ?? null;
        $zones = $this->requestData['zones'] ?? [];

        return [
            'is_readonly' => $this->getSafeRequestValue('is_readonly') === 'on',
            'operations' => is_array($operations) ? $operations : null,
            'zones' => is_array($zones) ? $zones : [],
        ];
    }

    /**
     * Zones the current user may scope a key to: their own zones, or all zones
     * for an administrator. Returned as lightweight id/name pairs for the picker.
     *
     * @return array<int, array{id: int, name: string, utf8_name: string}>
     */
    private function getAssignableZones(): array
    {
        $userId = $this->getUserContextService()->getLoggedInUserId();
        $viewOthers = $this->hasPermission('user_is_ueberuser');

        $zones = $this->zoneRepository->listZones($userId, $viewOthers, [], 0, 100000);

        return array_map(static fn(array $zone): array => [
            'id' => (int) $zone['id'],
            'name' => $zone['name'],
            'utf8_name' => $zone['utf8_name'],
        ], $zones);
    }
}
