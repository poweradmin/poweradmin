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

/**
 * V1 API controller for user operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\v1;

use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

// Tag defined in OpenApiConfig class
class UserController extends V1ApiBaseController
{
    private DbUserRepository $userRepository;
    private ApiKeyRepositoryInterface $apiKeyRepository;

    /**
     * Constructor for UserController
     *
     * @param array $request The request data
     */
    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->userRepository = new DbUserRepository($this->db);
        $this->apiKeyRepository = new DbApiKeyRepository($this->db, $this->getConfig());
    }

    /**
     * Run the controller based on the action parameter
     */
    public function run(): void
    {
        $method = $this->request->getMethod();
        $action = $this->request->query->get('action', '');

        $response = match ($method) {
            'GET' => $this->handleGetRequest($action),
            'POST' => $this->handlePostRequest($action),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * Handle GET requests
     *
     * @param string $action The action to perform
     * @return JsonResponse The JSON response
     */
    private function handleGetRequest(string $action): JsonResponse
    {
        return match ($action) {
            'verify' => $this->verifyUser(),
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * Handle POST requests
     *
     * @param string $action The action to perform
     * @return JsonResponse The JSON response
     */
    private function handlePostRequest(string $action): JsonResponse
    {
        return match ($action) {
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * Verify a user and API key combination
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/api/v1/user/verify',
        operationId: 'v1UserVerify',
        summary: 'Verify a user and API key',
        tags: ['users'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'action',
        in: 'query',
        required: true,
        description: 'Action parameter (must be \'verify\')',
        schema: new OA\Schema(type: 'string', default: 'verify', enum: ['verify'])
    )]
    #[OA\Response(
        response: 200,
        description: 'User and API key verification result',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'valid', type: 'boolean', example: true),
                        new OA\Property(property: 'user_id', type: 'integer', example: 1),
                        new OA\Property(property: 'username', type: 'string', example: 'admin'),
                        new OA\Property(
                            property: 'permissions',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'is_admin', type: 'boolean', example: true),
                                new OA\Property(property: 'zone_creation_allowed', type: 'boolean', example: true),
                                new OA\Property(property: 'zone_management_allowed', type: 'boolean', example: true)
                            ]
                        )
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid or missing API key'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    private function verifyUser(): JsonResponse
    {
        // Get API key used for the request
        $apiKey = $this->getApiKeyFromRequest();

        if (!$apiKey) {
            return $this->returnApiError('Invalid or missing API key', 401);
        }

        // Find the API key in the database
        $apiKeyEntity = $this->apiKeyRepository->findBySecretKey($apiKey);

        if (!$apiKeyEntity || !$apiKeyEntity->isValid()) {
            return $this->returnApiError('API key is invalid, disabled, or expired', 401);
        }

        // Get user associated with the API key
        $userId = $apiKeyEntity->getCreatedBy();
        if (!$userId) {
            return $this->returnApiError('No user associated with this API key', 401);
        }

        // Get user details
        $user = $this->userRepository->getUserById($userId);
        if (!$user) {
            return $this->returnApiError('User not found', 404);
        }

        // Check user permissions
        $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $canCreateZones = UserManager::verifyPermission($this->db, 'zone_master_add');
        $canManageZones = UserManager::verifyPermission($this->db, 'zone_content_edit_own') ||
                          UserManager::verifyPermission($this->db, 'zone_content_edit_others');

        return $this->returnApiResponse([
            'valid' => true,
            'user_id' => $userId,
            'username' => $user['username'],
            'permissions' => [
                'is_admin' => $isAdmin,
                'zone_creation_allowed' => $canCreateZones,
                'zone_management_allowed' => $canManageZones
            ]
        ]);
    }
}
