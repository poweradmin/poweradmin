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
 * RESTful API v2 controller for group zone assignment operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

class GroupZonesController extends PublicApiController
{
    private ZoneGroupService $zoneGroupService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $zoneGroupRepository = new DbZoneGroupRepository($this->db);
        $groupRepository = new DbUserGroupRepository($this->db);
        $this->zoneGroupService = new ZoneGroupService($zoneGroupRepository, $groupRepository);
    }

    /**
     * Handle group zone-related requests
     */
    #[\Override]
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => $this->listZones(),
            'POST' => $this->assignZone(),
            'DELETE' => $this->unassignZone(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * List zones assigned to group
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/groups/{id}/zones',
        operationId: 'v2ListGroupZones',
        description: 'Retrieves a list of zones assigned to a group',
        summary: 'List group zones',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['groups'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Group ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Zones retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Zones retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'zone_id', type: 'integer', example: 1),
                            new OA\Property(property: 'zone_name', type: 'string', example: 'example.com'),
                            new OA\Property(property: 'zone_type', type: 'string', example: 'MASTER'),
                            new OA\Property(property: 'assigned_at', type: 'string', example: '2025-01-01 12:00:00'),
                        ],
                        type: 'object'
                    )
                )
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    private function listZones(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->returnApiError('Only administrators can view group zones', 403);
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $zones = $this->zoneGroupService->listGroupZones($groupId);

            $zonesData = array_map(fn($z) => [
                'zone_id' => $z->getDomainId(),
                'zone_name' => $z->getName(),
                'zone_type' => $z->getType(),
                'assigned_at' => $z->getAssignedAt(),
            ], $zones);

            return $this->returnApiResponse($zonesData, true, 'Zones retrieved successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Assign zone to group
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/v2/groups/{id}/zones',
        operationId: 'v2AssignGroupZone',
        description: 'Assigns a zone to a group',
        summary: 'Assign zone to group',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['zone_id'],
                properties: [
                    new OA\Property(property: 'zone_id', type: 'integer', example: 10),
                ]
            )
        ),
        tags: ['groups'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Group ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ]
    )]
    #[OA\Response(
        response: 201,
        description: 'Zone assigned successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Zone assigned successfully'),
                new OA\Property(property: 'data', type: 'object', nullable: true)
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    private function assignZone(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->returnApiError('Only administrators can assign zones to groups', 403);
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $data = $this->getRequestData();

            if (empty($data['zone_id'])) {
                return $this->returnApiError('Missing required field: zone_id', 400);
            }

            $zoneId = (int)$data['zone_id'];
            $this->zoneGroupService->addGroupToZone($zoneId, $groupId);

            return $this->returnApiResponse(null, true, 'Zone assigned successfully', 201);
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 400);
        }
    }

    /**
     * Unassign zone from group
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Delete(
        path: '/v2/groups/{id}/zones/{zone_id}',
        operationId: 'v2UnassignGroupZone',
        description: 'Removes a zone from a group',
        summary: 'Unassign zone from group',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['groups'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Group ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'zone_id',
                description: 'Zone ID to unassign',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Zone unassigned successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Zone unassigned successfully'),
                new OA\Property(property: 'data', type: 'object', nullable: true)
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone assignment not found')]
    private function unassignZone(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->returnApiError('Only administrators can unassign zones from groups', 403);
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $zoneId = (int)($this->pathParameters['zone_id'] ?? 0);

            if ($zoneId === 0) {
                return $this->returnApiError('Invalid zone_id', 400);
            }

            $success = $this->zoneGroupService->removeGroupFromZone($zoneId, $groupId);

            if (!$success) {
                return $this->returnApiError('Zone assignment not found', 404);
            }

            return $this->returnApiResponse(null, true, 'Zone unassigned successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }
}
