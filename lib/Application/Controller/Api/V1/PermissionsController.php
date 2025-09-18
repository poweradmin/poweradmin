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
 * RESTful API controller for permission operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V1;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

class PermissionsController extends PublicApiController
{
    private DbPermissionTemplateRepository $permissionTemplateRepository;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);
        $this->permissionTemplateRepository = new DbPermissionTemplateRepository($this->db, $this->config);
    }

    /**
     * Handle permission requests
     */
    #[\Override]
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['id']) ? $this->getPermission() : $this->listPermissions(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * Get list of available permissions
     */
    #[OA\Get(
        path: '/v1/permissions',
        summary: 'Get list of available permissions',
        tags: ['Permissions'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of available permissions',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'data' => new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    'id' => new OA\Property(property: 'id', type: 'integer', example: 1),
                                    'name' => new OA\Property(property: 'name', type: 'string', example: 'zone_content_view_own'),
                                    'descr' => new OA\Property(property: 'descr', type: 'string', example: 'User may view the content of zones he owns')
                                ]
                            )
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function listPermissions(): JsonResponse
    {
        try {
            $permissions = $this->permissionTemplateRepository->getPermissionsByTemplateId(0);
            return $this->returnApiResponse($permissions);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to fetch permissions: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a specific permission by ID
     */
    private function getPermission(): JsonResponse
    {
        try {
            $permissionId = (int)($this->pathParameters['id'] ?? 0);
            if ($permissionId <= 0) {
                return $this->returnApiError('Valid permission ID is required', 400);
            }

            // Get all permissions and find the one with matching ID
            $permissions = $this->permissionTemplateRepository->getPermissionsByTemplateId(0);
            $permission = array_filter($permissions, fn($p) => (int)$p['id'] === $permissionId);

            if (empty($permission)) {
                return $this->returnApiError('Permission not found', 404);
            }

            return $this->returnApiResponse(array_values($permission)[0]);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to fetch permission: ' . $e->getMessage(), 500);
        }
    }
}
