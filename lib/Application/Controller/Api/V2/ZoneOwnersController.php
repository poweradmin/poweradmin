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
 * RESTful API v2 controller for zone owner operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

class ZoneOwnersController extends PublicApiController
{
    private DbZoneRepository $zoneRepository;
    private DbUserRepository $userRepository;
    private ApiPermissionService $apiPermissionService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = new DbZoneRepository($this->db, $this->config);
        $this->userRepository = new DbUserRepository($this->db, $this->config);
        $this->apiPermissionService = new ApiPermissionService($this->db);
    }

    /**
     * Handle zone owner-related requests
     */
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => $this->listOwners(),
            'POST' => $this->addOwner(),
            'DELETE' => $this->removeOwner(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * List zone owners
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/zones/{id}/owners',
        operationId: 'v2ListZoneOwners',
        description: 'Retrieves a list of owners for a zone',
        summary: 'List zone owners',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Zone ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Owners retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Owners retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'user_id', type: 'integer', example: 1),
                            new OA\Property(property: 'username', type: 'string', example: 'admin'),
                            new OA\Property(property: 'fullname', type: 'string', example: 'Administrator'),
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
    #[OA\Response(response: 404, description: 'Zone not found')]
    private function listOwners(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canViewZone($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to view this zone', 403);
        }

        try {
            $owners = $this->zoneRepository->getZoneOwners($zoneId);

            $ownersData = array_map(fn($o) => [
                'user_id' => $o['id'],
                'username' => $o['username'],
                'fullname' => $o['fullname'],
            ], $owners);

            return $this->returnApiResponse($ownersData, true, 'Owners retrieved successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Add owner(s) to zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/v2/zones/{id}/owners',
        operationId: 'v2AddZoneOwner',
        description: 'Adds one or more users as owners of a zone. Use user_id for a single user or user_ids for batch adding.',
        summary: 'Add owner(s) to zone',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer', example: 5, description: 'Single user ID to add'),
                    new OA\Property(
                        property: 'user_ids',
                        type: 'array',
                        items: new OA\Items(type: 'integer'),
                        example: [5, 6, 7],
                        description: 'List of user IDs to add (batch)'
                    ),
                ]
            )
        ),
        tags: ['zones'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Zone ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ]
    )]
    #[OA\Response(
        response: 201,
        description: 'Owner(s) added successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Owners added successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'added', type: 'array', items: new OA\Items(type: 'integer'), example: [5, 6]),
                        new OA\Property(property: 'skipped', type: 'array', items: new OA\Items(type: 'integer'), example: [7]),
                        new OA\Property(property: 'not_found', type: 'array', items: new OA\Items(type: 'integer'), example: []),
                    ],
                    type: 'object',
                    nullable: true
                )
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone not found')]
    #[OA\Response(response: 409, description: 'User is already an owner of this zone (single mode only)')]
    private function addOwner(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canEditZoneMeta($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to edit zone ownership', 403);
        }

        try {
            $data = json_decode($this->request->getContent(), true);

            // Batch mode: user_ids array
            if (!empty($data['user_ids']) && is_array($data['user_ids'])) {
                return $this->addOwnersBatch($zoneId, $data['user_ids']);
            }

            // Single mode: user_id
            if (empty($data['user_id'])) {
                return $this->returnApiError('Missing required field: user_id or user_ids', 400);
            }

            $userId = (int)$data['user_id'];

            if ($this->userRepository->getUserById($userId) === null) {
                return $this->returnApiError('User not found', 404);
            }

            if ($this->zoneRepository->isUserZoneOwner($zoneId, $userId)) {
                return $this->returnApiError('User is already an owner of this zone', 409);
            }

            $this->zoneRepository->addOwnerToZone($zoneId, $userId);

            return $this->returnApiResponse(null, true, 'Owner added successfully', 201);
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 400);
        }
    }

    /**
     * Add multiple owners to a zone in batch
     *
     * @param int $zoneId Zone ID
     * @param array<int|string> $userIds Array of user IDs
     * @return JsonResponse The JSON response
     */
    private function addOwnersBatch(int $zoneId, array $userIds): JsonResponse
    {
        $added = [];
        $skipped = [];
        $notFound = [];

        foreach ($userIds as $uid) {
            $userId = (int)$uid;

            if ($this->userRepository->getUserById($userId) === null) {
                $notFound[] = $userId;
                continue;
            }

            if ($this->zoneRepository->isUserZoneOwner($zoneId, $userId)) {
                $skipped[] = $userId;
                continue;
            }

            $this->zoneRepository->addOwnerToZone($zoneId, $userId);
            $added[] = $userId;
        }

        $message = count($added) . ' owner(s) added';
        if (!empty($skipped)) {
            $message .= ', ' . count($skipped) . ' already assigned';
        }
        if (!empty($notFound)) {
            $message .= ', ' . count($notFound) . ' not found';
        }

        return $this->returnApiResponse(
            ['added' => $added, 'skipped' => $skipped, 'not_found' => $notFound],
            true,
            $message,
            201
        );
    }

    /**
     * Remove owner from zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Delete(
        path: '/v2/zones/{id}/owners/{user_id}',
        operationId: 'v2RemoveZoneOwner',
        description: 'Removes a user from zone ownership',
        summary: 'Remove owner from zone',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Zone ID',
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
        description: 'Owner removed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Owner removed successfully'),
                new OA\Property(property: 'data', type: 'object', nullable: true)
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone or owner not found')]
    private function removeOwner(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canEditZoneMeta($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to edit zone ownership', 403);
        }

        try {
            $userId = (int)($this->pathParameters['user_id'] ?? 0);

            if ($userId === 0) {
                return $this->returnApiError('Invalid user_id', 400);
            }

            $success = $this->zoneRepository->removeOwnerFromZone($zoneId, $userId);

            if (!$success) {
                return $this->returnApiError('Owner not found for this zone', 404);
            }

            return $this->returnApiResponse(null, true, 'Owner removed successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }
}
