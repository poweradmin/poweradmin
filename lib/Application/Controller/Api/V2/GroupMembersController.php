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
 * RESTful API v2 controller for group member operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

class GroupMembersController extends PublicApiController
{
    private GroupMembershipService $membershipService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $memberRepository = new DbUserGroupMemberRepository($this->db);
        $groupRepository = new DbUserGroupRepository($this->db);
        $this->membershipService = new GroupMembershipService($memberRepository, $groupRepository);
    }

    /**
     * Handle group member-related requests
     */
    #[\Override]
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => $this->listMembers(),
            'POST' => $this->addMember(),
            'DELETE' => $this->removeMember(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * List group members
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/groups/{id}/members',
        operationId: 'v2ListGroupMembers',
        description: 'Retrieves a list of members in a group',
        summary: 'List group members',
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
        description: 'Members retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Members retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'user_id', type: 'integer', example: 1),
                            new OA\Property(property: 'username', type: 'string', example: 'admin'),
                            new OA\Property(property: 'fullname', type: 'string', example: 'Administrator'),
                            new OA\Property(property: 'email', type: 'string', example: 'admin@example.com'),
                            new OA\Property(property: 'joined_at', type: 'string', example: '2025-01-01 12:00:00'),
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
    private function listMembers(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->returnApiError('Only administrators can view group members', 403);
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $members = $this->membershipService->listGroupMembers($groupId);

            $membersData = array_map(fn($m) => [
                'user_id' => $m->getUserId(),
                'username' => $m->getUsername(),
                'fullname' => $m->getFullname(),
                'email' => $m->getEmail(),
                'joined_at' => $m->getJoinedAt(),
            ], $members);

            return $this->returnApiResponse($membersData, true, 'Members retrieved successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Add member to group
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/v2/groups/{id}/members',
        operationId: 'v2AddGroupMember',
        description: 'Adds a user to a group',
        summary: 'Add member to group',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_id'],
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer', example: 5),
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
        description: 'Member added successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Member added successfully'),
                new OA\Property(property: 'data', type: 'object', nullable: true)
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    private function addMember(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->returnApiError('Only administrators can add group members', 403);
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $data = $this->getRequestData();

            if (empty($data['user_id'])) {
                return $this->returnApiError('Missing required field: user_id', 400);
            }

            $userId = (int)$data['user_id'];
            $this->membershipService->addUserToGroup($groupId, $userId);

            return $this->returnApiResponse(null, true, 'Member added successfully', 201);
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 400);
        }
    }

    /**
     * Remove member from group
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Delete(
        path: '/v2/groups/{id}/members/{user_id}',
        operationId: 'v2RemoveGroupMember',
        description: 'Removes a user from a group',
        summary: 'Remove member from group',
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
                name: 'user_id',
                description: 'User ID to remove',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Member removed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Member removed successfully'),
                new OA\Property(property: 'data', type: 'object', nullable: true)
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Member not found')]
    private function removeMember(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->returnApiError('Only administrators can remove group members', 403);
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $userId = (int)($this->pathParameters['user_id'] ?? 0);

            if ($userId === 0) {
                return $this->returnApiError('Invalid user_id', 400);
            }

            $success = $this->membershipService->removeUserFromGroup($groupId, $userId);

            if (!$success) {
                return $this->returnApiError('Member not found in group', 404);
            }

            return $this->returnApiResponse(null, true, 'Member removed successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }
}
