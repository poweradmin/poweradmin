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

namespace Poweradmin\Application\Controller\Api\v1;

use OpenApi\Attributes as OA;
use Poweradmin\Application\Controller\Api\ApiBaseController;
use Poweradmin\Domain\Model\UserEntity;
use Poweradmin\Domain\Model\UserManager;

/**
 * API controller for testing authentication methods
 */
// Tag defined in OpenApiConfig class
class AuthTestController extends ApiBaseController
{
    /**
     * Test API authentication
     *
     * @return void
     */
    #[OA\Get(
        path: '/api/v1/auth/test',
        operationId: 'v1AuthTest',
        description: 'Verifies the current authentication credentials and returns user information',
        summary: 'Test API authentication credentials',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['users']
    )]
    #[OA\Response(
        response: 200,
        description: 'Authentication successful',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'authenticated', type: 'boolean', example: true),
                new OA\Property(property: 'user_id', type: 'integer', example: 1),
                new OA\Property(property: 'username', type: 'string', example: 'admin'),
                new OA\Property(property: 'auth_method', type: 'string', example: 'api_key'),
                new OA\Property(property: 'is_admin', type: 'boolean', example: true),
                new OA\Property(
                    property: 'permissions',
                    type: 'array',
                    items: new OA\Items(type: 'string')
                ),
                new OA\Property(property: 'server_time', type: 'string', example: '2025-05-09 08:30:00')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Authentication failed',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Authentication required'),
                new OA\Property(property: 'code', type: 'string', example: 'auth_required')
            ]
        )
    )]
    public function run(): void
    {
        // Check if user is authenticated
        if (!isset($_SESSION['userid'])) {
            $this->returnErrorResponse('Authentication required', 401, 'auth_required');
            return;
        }

        // Get user details
        $userId = $_SESSION['userid'] ?? 0;
        $authMethod = $_SESSION['auth_used'] ?? 'unknown';
        $username = UserEntity::getUserNameById($this->db, $userId);
        $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');

        // Return success response with authentication details
        $this->returnJsonResponse([
            'authenticated' => true,
            'user_id' => $userId,
            'username' => $username,
            'auth_method' => $authMethod,
            'is_admin' => $isAdmin,
            'permissions' => $this->getUserPermissions(),
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
