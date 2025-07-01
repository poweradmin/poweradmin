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
 * RESTful API controller for permission template operations
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

class PermissionTemplatesController extends PublicApiController
{
    private DbPermissionTemplateRepository $permissionTemplateRepository;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);
        $this->permissionTemplateRepository = new DbPermissionTemplateRepository($this->db);
    }

    /**
     * Handle permission template requests
     */
    #[\Override]
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['id']) ? $this->getPermissionTemplate() : $this->listPermissionTemplates(),
            'POST' => $this->createPermissionTemplate(),
            'PUT' => $this->updatePermissionTemplate(),
            'DELETE' => $this->deletePermissionTemplate(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * Get list of permission templates
     */
    #[OA\Get(
        path: '/v1/permission_templates',
        summary: 'Get list of permission templates',
        tags: ['Permission Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of permission templates',
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
                                    'name' => new OA\Property(property: 'name', type: 'string', example: 'Zone Administrator'),
                                    'descr' => new OA\Property(property: 'descr', type: 'string', example: 'Administrator for zones')
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
    private function listPermissionTemplates(): JsonResponse
    {
        try {
            $templates = $this->permissionTemplateRepository->listPermissionTemplates();
            return $this->returnApiResponse($templates);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to fetch permission templates: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get specific permission template
     */
    #[OA\Get(
        path: '/v1/permission_templates/{id}',
        summary: 'Get specific permission template',
        tags: ['Permission Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Permission template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Permission template details',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'data' => new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                'id' => new OA\Property(property: 'id', type: 'integer', example: 1),
                                'name' => new OA\Property(property: 'name', type: 'string', example: 'Zone Administrator'),
                                'descr' => new OA\Property(property: 'descr', type: 'string', example: 'Administrator for zones'),
                                'permissions' => new OA\Property(
                                    property: 'permissions',
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
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Permission template not found'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function getPermissionTemplate(): JsonResponse
    {
        try {
            $id = $this->pathParameters['id'];

            $template = $this->permissionTemplateRepository->getPermissionTemplateDetails($id);
            if (!$template) {
                return $this->returnApiError('Permission template not found', 404);
            }

            $template['permissions'] = $this->permissionTemplateRepository->getPermissionsByTemplateId($id);

            return $this->returnApiResponse($template);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to fetch permission template: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create new permission template
     */
    #[OA\Post(
        path: '/v1/permission_templates',
        summary: 'Create new permission template',
        tags: ['Permission Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['name', 'descr'],
                properties: [
                    'name' => new OA\Property(property: 'name', type: 'string', example: 'Zone Administrator'),
                    'descr' => new OA\Property(property: 'descr', type: 'string', example: 'Administrator for zones'),
                    'permissions' => new OA\Property(
                        property: 'permissions',
                        type: 'array',
                        items: new OA\Items(type: 'integer'),
                        example: [1, 2, 3]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Permission template created successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'message' => new OA\Property(property: 'message', type: 'string', example: 'Permission template created successfully')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Bad Request'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function createPermissionTemplate(): JsonResponse
    {
        try {
            $data = $this->getJsonInput();

            if (
                !$data || !isset($data['name']) || !isset($data['descr']) ||
                empty(trim($data['name'])) || empty(trim($data['descr']))
            ) {
                return $this->returnApiError('Missing required fields: name, descr', 400);
            }

            $details = [
                'templ_name' => $data['name'],
                'templ_descr' => $data['descr']
            ];

            if (isset($data['permissions']) && is_array($data['permissions'])) {
                $details['perm_id'] = $data['permissions'];
            }

            $result = $this->permissionTemplateRepository->addPermissionTemplate($details);

            if ($result) {
                return $this->returnApiResponse(null, true, 'Permission template created successfully', 201);
            } else {
                return $this->returnApiError('Failed to create permission template', 500);
            }
        } catch (Exception $e) {
            return $this->returnApiError('Failed to create permission template: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update permission template
     */
    #[OA\Put(
        path: '/v1/permission_templates/{id}',
        summary: 'Update permission template',
        tags: ['Permission Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Permission template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['name', 'descr'],
                properties: [
                    'name' => new OA\Property(property: 'name', type: 'string', example: 'Zone Administrator'),
                    'descr' => new OA\Property(property: 'descr', type: 'string', example: 'Administrator for zones'),
                    'permissions' => new OA\Property(
                        property: 'permissions',
                        type: 'array',
                        items: new OA\Items(type: 'integer'),
                        example: [1, 2, 3]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Permission template updated successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'message' => new OA\Property(property: 'message', type: 'string', example: 'Permission template updated successfully')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Bad Request'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Permission template not found'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function updatePermissionTemplate(): JsonResponse
    {
        try {
            if (!isset($this->pathParameters['id'])) {
                return $this->returnApiError('Permission template ID is required', 400);
            }

            $id = $this->pathParameters['id'];
            $data = $this->getJsonInput();

            if (
                !$data || !isset($data['name']) || !isset($data['descr']) ||
                empty(trim($data['name'])) || empty(trim($data['descr']))
            ) {
                return $this->returnApiError('Missing required fields: name, descr', 400);
            }

            $existing = $this->permissionTemplateRepository->getPermissionTemplateDetails($id);
            if (!$existing) {
                return $this->returnApiError('Permission template not found', 404);
            }

            $details = [
                'templ_id' => $id,
                'templ_name' => $data['name'],
                'templ_descr' => $data['descr']
            ];

            if (isset($data['permissions']) && is_array($data['permissions'])) {
                $details['perm_id'] = $data['permissions'];
            }

            $result = $this->permissionTemplateRepository->updatePermissionTemplateDetails($details);

            if ($result) {
                return $this->returnApiResponse(null, true, 'Permission template updated successfully');
            } else {
                return $this->returnApiError('Failed to update permission template', 500);
            }
        } catch (Exception $e) {
            return $this->returnApiError('Failed to update permission template: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete permission template
     */
    #[OA\Delete(
        path: '/v1/permission_templates/{id}',
        summary: 'Delete permission template',
        tags: ['Permission Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Permission template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Permission template deleted successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'message' => new OA\Property(property: 'message', type: 'string', example: 'Permission template deleted successfully')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Permission template not found'),
            new OA\Response(response: 409, description: 'Conflict - Template is in use'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function deletePermissionTemplate(): JsonResponse
    {
        try {
            if (!isset($this->pathParameters['id'])) {
                return $this->returnApiError('Permission template ID is required', 400);
            }

            $id = $this->pathParameters['id'];

            $existing = $this->permissionTemplateRepository->getPermissionTemplateDetails($id);
            if (!$existing) {
                return $this->returnApiError('Permission template not found', 404);
            }

            $result = $this->permissionTemplateRepository->deletePermissionTemplate($id);

            if ($result) {
                return $this->returnApiResponse(null, true, 'Permission template deleted successfully');
            } else {
                return $this->returnApiError('Cannot delete permission template - it is assigned to one or more users', 409);
            }
        } catch (Exception $e) {
            return $this->returnApiError('Failed to delete permission template: ' . $e->getMessage(), 500);
        }
    }
}
