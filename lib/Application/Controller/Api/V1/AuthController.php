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
 * V1 API controller for authentication operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\v1;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Model\UserEntity;
use Poweradmin\Domain\Model\UserManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class AuthController extends PublicApiController
{
    /**
     * Constructor for AuthController
     *
     * @param array $request The request data
     */
    public function __construct(array $request)
    {
        parent::__construct($request);
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
            'test' => $this->testAuth(),
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * Test API authentication and return user information
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/api/v1/auth/test',
        operationId: 'v1AuthTest',
        description: 'Verifies the current authentication credentials and returns user information',
        summary: 'Test API authentication',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['auth']
    )]
    #[OA\Parameter(
        name: 'action',
        description: 'Action parameter (must be \'test\')',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string', default: 'test', enum: ['test'])
    )]
    #[OA\Response(
        response: 200,
        description: 'Authentication successful',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'authenticated', type: 'boolean', example: true),
                        new OA\Property(property: 'user_id', type: 'integer', example: 1),
                        new OA\Property(property: 'username', type: 'string', example: 'admin'),
                        new OA\Property(property: 'auth_method', type: 'string', example: 'api_key'),
                        new OA\Property(property: 'is_admin', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'permissions',
                            properties: [
                                new OA\Property(property: 'is_admin', type: 'boolean', example: true),
                                new OA\Property(property: 'zone_creation_allowed', type: 'boolean', example: true),
                                new OA\Property(property: 'zone_management_allowed', type: 'boolean', example: true)
                            ],
                            type: 'object'
                        ),
                        new OA\Property(property: 'server_time', type: 'string', example: '2025-05-09 08:30:00')
                    ],
                    type: 'object'
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
    private function testAuth(): JsonResponse
    {
        // Check if user is authenticated
        $authenticated = isset($_SESSION['userid']);
        $userId = $_SESSION['userid'] ?? 0;
        $authMethod = $_SESSION['auth_used'] ?? 'unknown';

        // If not authenticated, return error
        if (!$authenticated) {
            return $this->returnApiError('Authentication failed', 401);
        }

        // Get user details
        $username = UserEntity::getUserNameById($this->db, $userId);

        // Check user permissions
        $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $canCreateZones = UserManager::verifyPermission($this->db, 'zone_master_add');
        $canManageZones = UserManager::verifyPermission($this->db, 'zone_content_edit_own') ||
                          UserManager::verifyPermission($this->db, 'zone_content_edit_others');

        return $this->returnApiResponse([
            'authenticated' => $authenticated,
            'user_id' => $userId,
            'username' => $username,
            'auth_method' => $authMethod,
            'is_admin' => $isAdmin,
            'permissions' => [
                'is_admin' => $isAdmin,
                'zone_creation_allowed' => $canCreateZones,
                'zone_management_allowed' => $canManageZones
            ],
            'server_time' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get a list of permissions for the current user
     *
     * @return array Array of permission names
     */
    private function getUserPermissions(): array
    {
        $permissions = UserManager::getPermissionsByTemplateId($this->db, 0, true);
        $userPermissions = [];

        foreach ($permissions as $permission) {
            if (UserManager::verifyPermission($this->db, $permission)) {
                $userPermissions[] = $permission;
            }
        }

        return $userPermissions;
    }
}
