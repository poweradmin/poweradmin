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
 * RESTful API controller for zone template record operations
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

class ZoneTemplateRecordsController extends PublicApiController
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
     * Handle zone template record requests
     */
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['template_id']) ? $this->getRecord() : $this->listRecords(),
            'POST' => $this->createRecord(),
            'PUT' => $this->updateRecord(),
            'DELETE' => $this->deleteRecord(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    private function canViewTemplate(int $userId, int $templateId): bool
    {
        if ($this->apiPermissionService->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        $owner = $this->repository->getOwner($templateId);
        if ($owner === 0) {
            return true;
        }

        return $this->repository->isOwner($templateId, $userId);
    }

    private function canEditTemplate(int $userId, int $templateId): bool
    {
        if (!$this->apiPermissionService->canEditZoneTemplate($userId)) {
            return false;
        }

        if ($this->apiPermissionService->userHasPermission($userId, 'user_is_ueberuser')) {
            return true;
        }

        $owner = $this->repository->getOwner($templateId);
        if ($owner === 0) {
            return false;
        }

        return $this->repository->isOwner($templateId, $userId);
    }

    private function formatRecord(array $record): array
    {
        return [
            'id' => (int)$record['id'],
            'name' => $record['name'],
            'type' => $record['type'],
            'content' => $record['content'],
            'ttl' => (int)$record['ttl'],
            'priority' => (int)($record['prio'] ?? 0),
        ];
    }

    #[OA\Get(
        path: '/v2/zone-templates/{id}/records',
        summary: 'List all records in a zone template',
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
                description: 'List of zone template records',
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
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone template not found'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function listRecords(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $templateId = (int)$this->pathParameters['id'];

            if (!$this->repository->zoneTemplateExists($templateId)) {
                return $this->returnApiError('Zone template not found', 404);
            }

            if (!$this->canViewTemplate($userId, $templateId)) {
                return $this->returnApiError('You do not have permission to view this zone template', 403);
            }

            $records = $this->repository->getZoneTemplateRecords($templateId);
            $formattedRecords = array_map([$this, 'formatRecord'], $records);

            return $this->returnApiResponse(array_values($formattedRecords));
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZoneTemplateRecordsController::listRecords', 'Failed to fetch zone template records');
        }
    }

    #[OA\Post(
        path: '/v2/zone-templates/{id}/records',
        summary: 'Create a new record in a zone template',
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
                required: ['name', 'type', 'content'],
                properties: [
                    'name' => new OA\Property(property: 'name', type: 'string', example: '[ZONE]'),
                    'type' => new OA\Property(property: 'type', type: 'string', example: 'A'),
                    'content' => new OA\Property(property: 'content', type: 'string', example: '192.168.1.1'),
                    'ttl' => new OA\Property(property: 'ttl', type: 'integer', example: 86400),
                    'priority' => new OA\Property(property: 'priority', type: 'integer', example: 0)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Zone template record created successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'data' => new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                'id' => new OA\Property(property: 'id', type: 'integer', example: 5)
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Bad Request'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone template not found'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function createRecord(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $templateId = (int)$this->pathParameters['id'];

            if (!$this->repository->zoneTemplateExists($templateId)) {
                return $this->returnApiError('Zone template not found', 404);
            }

            if (!$this->canEditTemplate($userId, $templateId)) {
                return $this->returnApiError('You do not have permission to edit this zone template', 403);
            }

            $data = $this->getJsonInput();

            if (
                !$data || !isset($data['name']) || !isset($data['type']) || !isset($data['content']) ||
                trim($data['name']) === '' || trim($data['type']) === '' || trim($data['content']) === ''
            ) {
                return $this->returnApiError('Missing required fields: name, type, content', 400);
            }

            $name = trim($data['name']);
            $type = trim($data['type']);
            $content = trim($data['content']);
            $ttl = isset($data['ttl']) ? (int)$data['ttl'] : (int)$this->config->get('dns', 'ttl');
            $priority = isset($data['priority']) ? (int)$data['priority'] : 0;

            $recordId = $this->repository->addRecord($templateId, $name, $type, $content, $ttl, $priority);

            return $this->returnApiResponse(['id' => $recordId], true, null, 201);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZoneTemplateRecordsController::createRecord', 'Failed to create zone template record');
        }
    }

    #[OA\Get(
        path: '/v2/zone-templates/{template_id}/records/{id}',
        summary: 'Get a specific record in a zone template',
        tags: ['Zone Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(
                name: 'template_id',
                description: 'Zone template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'id',
                description: 'Record ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Zone template record details',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'data' => new OA\Property(
                            property: 'data',
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
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Record not found'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function getRecord(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $templateId = (int)$this->pathParameters['template_id'];
            $recordId = (int)$this->pathParameters['id'];

            if (!$this->repository->zoneTemplateExists($templateId)) {
                return $this->returnApiError('Zone template not found', 404);
            }

            if (!$this->canViewTemplate($userId, $templateId)) {
                return $this->returnApiError('You do not have permission to view this zone template', 403);
            }

            $record = $this->repository->getZoneTemplateRecordById($recordId);
            if (empty($record) || (int)$record['zone_templ_id'] !== $templateId) {
                return $this->returnApiError('Record not found in this zone template', 404);
            }

            return $this->returnApiResponse($this->formatRecord($record));
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZoneTemplateRecordsController::getRecord', 'Failed to fetch zone template record');
        }
    }

    #[OA\Put(
        path: '/v2/zone-templates/{template_id}/records/{id}',
        summary: 'Update a record in a zone template',
        tags: ['Zone Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(
                name: 'template_id',
                description: 'Zone template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'id',
                description: 'Record ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['name', 'type', 'content'],
                properties: [
                    'name' => new OA\Property(property: 'name', type: 'string', example: '[ZONE]'),
                    'type' => new OA\Property(property: 'type', type: 'string', example: 'A'),
                    'content' => new OA\Property(property: 'content', type: 'string', example: '192.168.1.2'),
                    'ttl' => new OA\Property(property: 'ttl', type: 'integer', example: 86400),
                    'priority' => new OA\Property(property: 'priority', type: 'integer', example: 0)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Zone template record updated successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'message' => new OA\Property(property: 'message', type: 'string', example: 'Zone template record updated successfully')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Bad Request'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Record not found'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function updateRecord(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $templateId = (int)$this->pathParameters['template_id'];
            $recordId = (int)$this->pathParameters['id'];

            if (!$this->repository->zoneTemplateExists($templateId)) {
                return $this->returnApiError('Zone template not found', 404);
            }

            if (!$this->canEditTemplate($userId, $templateId)) {
                return $this->returnApiError('You do not have permission to edit this zone template', 403);
            }

            $record = $this->repository->getZoneTemplateRecordById($recordId);
            if (empty($record) || (int)$record['zone_templ_id'] !== $templateId) {
                return $this->returnApiError('Record not found in this zone template', 404);
            }

            $data = $this->getJsonInput();

            if (
                !$data || !isset($data['name']) || !isset($data['type']) || !isset($data['content']) ||
                trim($data['name']) === '' || trim($data['type']) === '' || trim($data['content']) === ''
            ) {
                return $this->returnApiError('Missing required fields: name, type, content', 400);
            }

            $name = trim($data['name']);
            $type = trim($data['type']);
            $content = trim($data['content']);
            $ttl = isset($data['ttl']) ? (int)$data['ttl'] : (int)$record['ttl'];
            $priority = isset($data['priority']) ? (int)$data['priority'] : (int)($record['prio'] ?? 0);

            $this->repository->updateRecord($recordId, $name, $type, $content, $ttl, $priority);

            return $this->returnApiResponse(null, true, 'Zone template record updated successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZoneTemplateRecordsController::updateRecord', 'Failed to update zone template record');
        }
    }

    #[OA\Delete(
        path: '/v2/zone-templates/{template_id}/records/{id}',
        summary: 'Delete a record from a zone template',
        tags: ['Zone Templates'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(
                name: 'template_id',
                description: 'Zone template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'id',
                description: 'Record ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Zone template record deleted successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'success' => new OA\Property(property: 'success', type: 'boolean', example: true),
                        'message' => new OA\Property(property: 'message', type: 'string', example: 'Zone template record deleted successfully')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Record not found'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    private function deleteRecord(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $templateId = (int)$this->pathParameters['template_id'];
            $recordId = (int)$this->pathParameters['id'];

            if (!$this->repository->zoneTemplateExists($templateId)) {
                return $this->returnApiError('Zone template not found', 404);
            }

            if (!$this->canEditTemplate($userId, $templateId)) {
                return $this->returnApiError('You do not have permission to edit this zone template', 403);
            }

            $record = $this->repository->getZoneTemplateRecordById($recordId);
            if (empty($record) || (int)$record['zone_templ_id'] !== $templateId) {
                return $this->returnApiError('Record not found in this zone template', 404);
            }

            $this->repository->deleteRecord($recordId);

            return $this->returnApiResponse(null, true, 'Zone template record deleted successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZoneTemplateRecordsController::deleteRecord', 'Failed to delete zone template record');
        }
    }
}
