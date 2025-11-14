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
 * RESTful API v2 controller for group operations with wrapped responses
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\GroupService;
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

class GroupsController extends PublicApiController
{
    private GroupService $groupService;
    private GroupMembershipService $membershipService;
    private ZoneGroupService $zoneGroupService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $groupRepository = new DbUserGroupRepository($this->db);
        $memberRepository = new DbUserGroupMemberRepository($this->db);
        $zoneGroupRepository = new DbZoneGroupRepository($this->db);

        $this->groupService = new GroupService($groupRepository);
        $this->membershipService = new GroupMembershipService($memberRepository, $groupRepository);
        $this->zoneGroupService = new ZoneGroupService($zoneGroupRepository, $groupRepository);
    }

    /**
     * Handle group-related requests
     */
    #[\Override]
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['id']) ? $this->getGroup() : $this->listGroups(),
            'POST' => $this->createGroup(),
            'PUT' => $this->updateGroup(),
            'DELETE' => $this->deleteGroup(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * List all groups
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/groups',
        operationId: 'v2ListGroups',
        description: 'Retrieves a list of all user groups with wrapped response',
        summary: 'List all groups',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['groups']
    )]
    #[OA\Response(
        response: 200,
        description: 'Groups retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Groups retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'name', type: 'string', example: 'DNS Admins'),
                            new OA\Property(property: 'description', type: 'string', example: 'DNS Administration Group'),
                            new OA\Property(property: 'perm_templ_id', type: 'integer', example: 1),
                            new OA\Property(property: 'member_count', type: 'integer', example: 5),
                            new OA\Property(property: 'zone_count', type: 'integer', example: 10),
                            new OA\Property(property: 'created_at', type: 'string', example: '2025-01-01 12:00:00'),
                        ],
                        type: 'object'
                    )
                )
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    private function listGroups(): JsonResponse
    {
        try {
            $userId = $this->authenticatedUserId;
            $isAdmin = $this->isAdmin();

            $groups = $this->groupService->listGroups($userId, $isAdmin);

            $enrichedGroups = [];
            foreach ($groups as $group) {
                $details = $this->groupService->getGroupDetails($group->getId());
                $enrichedGroups[] = [
                    'id' => $group->getId(),
                    'name' => $group->getName(),
                    'description' => $group->getDescription(),
                    'perm_templ_id' => $group->getPermTemplId(),
                    'member_count' => $details['memberCount'],
                    'zone_count' => $details['zoneCount'],
                    'created_at' => $group->getCreatedAt(),
                ];
            }

            return $this->returnApiSuccess($enrichedGroups, 'Groups retrieved successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Get group details
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/groups/{id}',
        operationId: 'v2GetGroup',
        description: 'Retrieves detailed information about a specific group',
        summary: 'Get group by ID',
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
        description: 'Group retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Group retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'name', type: 'string', example: 'DNS Admins'),
                        new OA\Property(property: 'description', type: 'string', example: 'DNS Administration Group'),
                        new OA\Property(property: 'perm_templ_id', type: 'integer', example: 1),
                        new OA\Property(property: 'member_count', type: 'integer', example: 5),
                        new OA\Property(property: 'zone_count', type: 'integer', example: 10),
                        new OA\Property(
                            property: 'members',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'user_id', type: 'integer', example: 1),
                                    new OA\Property(property: 'username', type: 'string', example: 'admin'),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(
                            property: 'zones',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'zone_id', type: 'integer', example: 1),
                                    new OA\Property(property: 'zone_name', type: 'string', example: 'example.com'),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(property: 'created_at', type: 'string', example: '2025-01-01 12:00:00'),
                    ],
                    type: 'object'
                )
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 404, description: 'Group not found')]
    private function getGroup(): JsonResponse
    {
        try {
            $groupId = (int)$this->pathParameters['id'];
            $userId = $this->authenticatedUserId;
            $isAdmin = $this->isAdmin();

            try {
                $group = $this->groupService->getGroupById($groupId, $userId, $isAdmin);
            } catch (\InvalidArgumentException $e) {
                // Permission denied
                return $this->returnApiError($e->getMessage(), 403);
            }

            if (!$group) {
                return $this->returnApiError('Group not found', 404);
            }

            $details = $this->groupService->getGroupDetails($groupId);
            $members = $this->membershipService->listGroupMembers($groupId);
            $zones = $this->zoneGroupService->listGroupZones($groupId);

            $data = [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'description' => $group->getDescription(),
                'perm_templ_id' => $group->getPermTemplId(),
                'member_count' => $details['memberCount'],
                'zone_count' => $details['zoneCount'],
                'members' => array_map(fn($m) => [
                    'user_id' => $m->getUserId(),
                    'username' => $m->getUsername(),
                ], $members),
                'zones' => array_map(fn($z) => [
                    'zone_id' => $z->getDomainId(),
                    'zone_name' => $z->getName(),
                ], $zones),
                'created_at' => $group->getCreatedAt(),
            ];

            return $this->returnApiSuccess($data, 'Group retrieved successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Create a new group
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/v2/groups',
        operationId: 'v2CreateGroup',
        description: 'Creates a new user group',
        summary: 'Create a new group',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'perm_templ_id'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'DNS Admins'),
                    new OA\Property(property: 'description', type: 'string', example: 'DNS Administration Group'),
                    new OA\Property(property: 'perm_templ_id', type: 'integer', example: 1),
                ]
            )
        ),
        tags: ['groups']
    )]
    #[OA\Response(
        response: 201,
        description: 'Group created successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Group created successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'name', type: 'string', example: 'DNS Admins'),
                    ],
                    type: 'object'
                )
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input')]
    private function createGroup(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->returnApiError('Only administrators can create groups', 403);
        }

        try {
            $data = $this->getRequestData();

            if (empty($data['name']) || empty($data['perm_templ_id'])) {
                return $this->returnApiError('Missing required fields: name, perm_templ_id', 400);
            }

            $group = $this->groupService->createGroup(
                $data['name'],
                (int)$data['perm_templ_id'],
                $data['description'] ?? '',
                $this->authenticatedUserId
            );

            return $this->returnApiSuccess([
                'id' => $group->getId(),
                'name' => $group->getName(),
            ], 'Group created successfully', 201);
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 400);
        }
    }

    /**
     * Update a group
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Put(
        path: '/v2/groups/{id}',
        operationId: 'v2UpdateGroup',
        description: 'Updates an existing group',
        summary: 'Update group',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'DNS Admins'),
                    new OA\Property(property: 'description', type: 'string', example: 'Updated description'),
                    new OA\Property(property: 'perm_templ_id', type: 'integer', example: 1),
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
        response: 200,
        description: 'Group updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Group updated successfully'),
                new OA\Property(property: 'data', type: 'object', nullable: true)
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 404, description: 'Group not found')]
    private function updateGroup(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->returnApiError('Only administrators can update groups', 403);
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $data = $this->getRequestData();

            $success = $this->groupService->updateGroup(
                $groupId,
                $data['name'] ?? null,
                $data['description'] ?? null,
                isset($data['perm_templ_id']) ? (int)$data['perm_templ_id'] : null
            );

            if (!$success) {
                return $this->returnApiError('Group not found or update failed', 404);
            }

            return $this->returnApiSuccess(null, 'Group updated successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 400);
        }
    }

    /**
     * Delete a group
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Delete(
        path: '/v2/groups/{id}',
        operationId: 'v2DeleteGroup',
        description: 'Deletes a group',
        summary: 'Delete group',
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
        description: 'Group deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Group deleted successfully'),
                new OA\Property(property: 'data', type: 'object', nullable: true)
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 404, description: 'Group not found')]
    private function deleteGroup(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->returnApiError('Only administrators can delete groups', 403);
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $success = $this->groupService->deleteGroup($groupId);

            if (!$success) {
                return $this->returnApiError('Group not found or deletion failed', 404);
            }

            return $this->returnApiSuccess(null, 'Group deleted successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Return API success response with wrapped format
     */
    private function returnApiSuccess(mixed $data = null, string $message = 'Success', int $statusCode = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Return API error response with wrapped format
     */
    protected function returnApiError(string $message, int $statusCode = 400): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $statusCode);
    }
}
