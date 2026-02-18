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

/**
 * RESTful API controller for zone template operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZoneTemplatesController extends PublicApiController
{
    private DbZoneTemplateRepository $repository;
    private ApiPermissionService $apiPermissionService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);
        $this->repository = new DbZoneTemplateRepository($this->db, $this->config);
        $this->apiPermissionService = new ApiPermissionService($this->db);
    }

    /**
     * Handle zone template requests
     */
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['id']) ? $this->getZoneTemplate() : $this->listZoneTemplates(),
            'POST' => $this->createZoneTemplate(),
            'PUT' => $this->updateZoneTemplate(),
            'DELETE' => $this->deleteZoneTemplate(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    #[OA\Get(
        path: '/v2/zone-templates',
        summary: 'Get list of zone templates',
        tags: ['Zone Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of zone templates',
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
                                    'name' => new OA\Property(property: 'name', type: 'string', example: 'Default Template'),
                                    'description' => new OA\Property(property: 'description', type: 'string', example: 'Default zone template'),
                                    'owner' => new OA\Property(property: 'owner', type: 'integer', example: 1),
                                    'is_global' => new OA\Property(property: 'is_global', type: 'boolean', example: false),
                                    'zones_linked' => new OA\Property(property: 'zones_linked', type: 'integer', example: 3)
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
    private function listZoneTemplates(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $isUeberuser = $this->apiPermissionService->userHasPermission($userId, 'user_is_ueberuser');

            $templates = $this->repository->listZoneTemplates($userId, $isUeberuser);

            $formatted = array_map(function (array $template): array {
                return [
                    'id' => (int)$template['id'],
                    'name' => $template['name'],
                    'description' => $template['descr'],
                    'owner' => (int)$template['owner'],
                    'is_global' => (int)$template['owner'] === 0,
                    'zones_linked' => (int)$template['zones_linked'],
                ];
            }, $templates);

            return $this->returnApiResponse($formatted);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZoneTemplatesController::listZoneTemplates', 'Failed to fetch zone templates');
        }
    }

    #[OA\Get(
        path: '/v2/zone-templates/{id}',
        summary: 'Get specific zone template with records',
        tags: ['Zone Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Zone template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Zone template details with records',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'data' => new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                'id' => new OA\Property(property: 'id', type: 'integer', example: 1),
                                'name' => new OA\Property(property: 'name', type: 'string', example: 'Default Template'),
                                'description' => new OA\Property(property: 'description', type: 'string', example: 'Default zone template'),
                                'owner' => new OA\Property(property: 'owner', type: 'integer', example: 1),
                                'is_global' => new OA\Property(property: 'is_global', type: 'boolean', example: false),
                                'records' => new OA\Property(
                                    property: 'records',
                                    type: 'array',
                                    items: new OA\Items(
                                        type: 'object',
                                        properties: [
                                            'id' => new OA\Property(property: 'id', type: 'integer', example: 1),
                                            'name' => new OA\Property(property: 'name', type: 'string', example: '[ZONE]'),
                                            'type' => new OA\Property(property: 'type', type: 'string', example: 'SOA'),
                                            'content' => new OA\Property(property: 'content', type: 'string', example: '[NS1] [HOSTMASTER] [SERIAL] 28800 7200 604800 86400'),
                                            'ttl' => new OA\Property(property: 'ttl', type: 'integer', example: 86400),
                                            'priority' => new OA\Property(property: 'priority', type: 'integer', example: 0)
                                        ]
                                    )
                                )
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone template not found'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function getZoneTemplate(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $isUeberuser = $this->apiPermissionService->userHasPermission($userId, 'user_is_ueberuser');

            $id = (int)$this->pathParameters['id'];

            $template = $this->repository->getZoneTemplateDetails($id);
            if (!$template) {
                return $this->returnApiError('Zone template not found', 404);
            }

            $owner = (int)$template['owner'];
            if ($owner !== 0 && $owner !== $userId && !$isUeberuser) {
                return $this->returnApiError('You do not have permission to view this zone template', 403);
            }

            $records = $this->repository->getZoneTemplateRecords($id);

            $formattedRecords = array_map(function (array $record): array {
                return [
                    'id' => (int)$record['id'],
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'content' => $record['content'],
                    'ttl' => (int)$record['ttl'],
                    'priority' => (int)$record['prio'],
                ];
            }, $records);

            return $this->returnApiResponse([
                'id' => (int)$template['id'],
                'name' => $template['name'],
                'description' => $template['descr'],
                'owner' => $owner,
                'is_global' => $owner === 0,
                'records' => $formattedRecords,
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZoneTemplatesController::getZoneTemplate', 'Failed to fetch zone template');
        }
    }

    #[OA\Post(
        path: '/v2/zone-templates',
        summary: 'Create new zone template',
        tags: ['Zone Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['name', 'description'],
                properties: [
                    'name' => new OA\Property(property: 'name', type: 'string', example: 'Default Template'),
                    'description' => new OA\Property(property: 'description', type: 'string', example: 'Default zone template'),
                    'is_global' => new OA\Property(
                        property: 'is_global',
                        type: 'boolean',
                        example: false,
                        description: 'Whether the template is global (owner=0). Requires ueberuser permission.'
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Zone template created successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'data' => new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                'id' => new OA\Property(property: 'id', type: 'integer', example: 1)
                            ]
                        ),
                        'message' => new OA\Property(property: 'message', type: 'string', example: 'Zone template created successfully')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Bad Request'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 409, description: 'Conflict - Template name already exists'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function createZoneTemplate(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            if (!$this->apiPermissionService->canCreateZoneTemplate($userId)) {
                return $this->returnApiError('You do not have permission to create zone templates', 403);
            }

            $data = $this->getJsonInput();

            if (
                !$data || !isset($data['name']) || !isset($data['description']) ||
                empty(trim($data['name'])) || empty(trim($data['description']))
            ) {
                return $this->returnApiError('Missing required fields: name, description', 400);
            }

            $name = trim($data['name']);
            $description = trim($data['description']);
            $isGlobal = !empty($data['is_global']);

            if ($isGlobal && !$this->apiPermissionService->userHasPermission($userId, 'user_is_ueberuser')) {
                return $this->returnApiError('Only ueberusers can create global zone templates', 403);
            }

            if ($this->repository->zoneTemplateNameExists($name)) {
                return $this->returnApiError('A zone template with this name already exists', 409);
            }

            $owner = $isGlobal ? 0 : $userId;
            $newId = $this->repository->createZoneTemplate($name, $description, $owner, $userId);

            return $this->returnApiResponse(['id' => $newId], true, 'Zone template created successfully', 201);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZoneTemplatesController::createZoneTemplate', 'Failed to create zone template');
        }
    }

    #[OA\Put(
        path: '/v2/zone-templates/{id}',
        summary: 'Update zone template',
        tags: ['Zone Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Zone template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['name', 'description'],
                properties: [
                    'name' => new OA\Property(property: 'name', type: 'string', example: 'Default Template'),
                    'description' => new OA\Property(property: 'description', type: 'string', example: 'Default zone template'),
                    'is_global' => new OA\Property(
                        property: 'is_global',
                        type: 'boolean',
                        example: false,
                        description: 'Whether the template is global (owner=0). Requires ueberuser permission.'
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Zone template updated successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'message' => new OA\Property(property: 'message', type: 'string', example: 'Zone template updated successfully')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Bad Request'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone template not found'),
            new OA\Response(response: 409, description: 'Conflict - Template name already exists'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function updateZoneTemplate(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            if (!$this->apiPermissionService->canEditZoneTemplate($userId)) {
                return $this->returnApiError('You do not have permission to edit zone templates', 403);
            }

            if (!isset($this->pathParameters['id'])) {
                return $this->returnApiError('Zone template ID is required', 400);
            }

            $id = (int)$this->pathParameters['id'];

            if (!$this->repository->zoneTemplateExists($id)) {
                return $this->returnApiError('Zone template not found', 404);
            }

            $isUeberuser = $this->apiPermissionService->userHasPermission($userId, 'user_is_ueberuser');
            $owner = $this->repository->getOwner($id);

            if ($owner === 0 && !$isUeberuser) {
                return $this->returnApiError('Only ueberusers can edit global zone templates', 403);
            }

            if ($owner !== 0 && $owner !== $userId && !$isUeberuser) {
                return $this->returnApiError('You do not have permission to edit this zone template', 403);
            }

            $data = $this->getJsonInput();

            if (
                !$data || !isset($data['name']) || !isset($data['description']) ||
                empty(trim($data['name'])) || empty(trim($data['description']))
            ) {
                return $this->returnApiError('Missing required fields: name, description', 400);
            }

            $name = trim($data['name']);
            $description = trim($data['description']);

            if ($this->repository->zoneTemplateNameExists($name, $id)) {
                return $this->returnApiError('A zone template with this name already exists', 409);
            }

            $newOwner = null;
            if (isset($data['is_global'])) {
                if ($data['is_global'] && !$isUeberuser) {
                    return $this->returnApiError('Only ueberusers can set templates as global', 403);
                }
                if ($data['is_global']) {
                    $newOwner = 0;
                } elseif ($owner === 0) {
                    // Converting from global to non-global: assign to current user
                    $newOwner = $userId;
                }
                // Otherwise: non-global staying non-global, preserve existing owner
            }

            $this->repository->updateZoneTemplate($id, $name, $description, $newOwner);

            return $this->returnApiResponse(null, true, 'Zone template updated successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZoneTemplatesController::updateZoneTemplate', 'Failed to update zone template');
        }
    }

    #[OA\Delete(
        path: '/v2/zone-templates/{id}',
        summary: 'Delete zone template',
        tags: ['Zone Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Zone template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Zone template deleted successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'message' => new OA\Property(property: 'message', type: 'string', example: 'Zone template deleted successfully')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone template not found'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function deleteZoneTemplate(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            if (!$this->apiPermissionService->canEditZoneTemplate($userId)) {
                return $this->returnApiError('You do not have permission to delete zone templates', 403);
            }

            if (!isset($this->pathParameters['id'])) {
                return $this->returnApiError('Zone template ID is required', 400);
            }

            $id = (int)$this->pathParameters['id'];

            if (!$this->repository->zoneTemplateExists($id)) {
                return $this->returnApiError('Zone template not found', 404);
            }

            $isUeberuser = $this->apiPermissionService->userHasPermission($userId, 'user_is_ueberuser');
            $owner = $this->repository->getOwner($id);

            if ($owner === 0 && !$isUeberuser) {
                return $this->returnApiError('Only ueberusers can delete global zone templates', 403);
            }

            if ($owner !== 0 && $owner !== $userId && !$isUeberuser) {
                return $this->returnApiError('You do not have permission to delete this zone template', 403);
            }

            $this->repository->deleteZoneTemplate($id);

            return $this->returnApiResponse(null, true, 'Zone template deleted successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZoneTemplatesController::deleteZoneTemplate', 'Failed to delete zone template');
        }
    }
}
