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

namespace Poweradmin\Application\Controller\Api\V2;

use Poweradmin\Domain\Error\GroupNotFoundException;
use InvalidArgumentException;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\Zone\ZoneGroupService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipRefusal;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

/**
 * /api/v2/groups/{id}/zones: lists, assigns and unassigns the zones a group owns.
 */
class GroupZonesController extends PublicApiController
{
    private ZoneGroupService $zoneGroupService;
    private ApiPermissionService $apiPermissionService;
    private DomainRepositoryInterface $domainRepository;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneGroupService = $this->services()->zoneGroupService();
        $this->apiPermissionService = $this->services()->apiPermissionService();
        $this->domainRepository = $this->services()->domainRepository();
    }

    /**
     * Handle group zone-related requests
     */
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => $this->listZones(),
            'POST' => $this->assignZone(),
            'DELETE' => $this->unassignZone(),
            default => $this->methodNotAllowed(['GET', 'POST', 'DELETE']),
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
    #[OA\Response(response: 403, description: 'Forbidden')]
    private function listZones(): JsonResponse
    {
        if (!$this->apiPermissionService->userHasPermission($this->authenticatedUserId, Permission::PERM_USER_IS_UEBERUSER)) {
            return $this->returnApiError('Only administrators can view group zones', 403);
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $zones = $this->zoneGroupService->listGroupZones($groupId);

            // Do not disclose zones outside a zone-scoped key's allowlist.
            $scope = $this->getApiKeyScope();
            $zonesData = [];
            foreach ($zones as $z) {
                if (!$scope->isZoneAllowed($z->getDomainId())) {
                    continue;
                }
                $zonesData[] = [
                    'zone_id' => $z->getDomainId(),
                    'zone_name' => $z->getName(),
                    'zone_type' => $z->getType(),
                    'created_at' => $z->getCreatedAt(),
                ];
            }

            return $this->returnApiResponse(['zones' => $zonesData], true, 'Zones retrieved successfully');
        } catch (GroupNotFoundException $e) {
            return $this->returnApiError($e->getMessage(), 404);
        } catch (Exception $e) {
            return $this->handleException($e, 'GroupZonesController::listZones', 'Failed to retrieve zones');
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
    #[OA\Response(response: 404, description: 'Zone not found')]
    private function assignZone(): JsonResponse
    {
        if (!$this->apiPermissionService->userHasPermission($this->authenticatedUserId, Permission::PERM_USER_IS_UEBERUSER)) {
            return $this->returnApiError('Only administrators can assign zones to groups', 403);
        }

        $ownershipMode = $this->services()->zoneOwnershipModeService();
        if (!$ownershipMode->isGroupOwnerAllowed()) {
            return $this->returnApiError(
                'Group-ownership assignment is disabled by the current zone ownership mode (users_only).',
                400
            );
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $data = $this->getValidatedJsonBody() ?? [];

            if (empty($data['zone_id'])) {
                return $this->returnApiError('Missing required field: zone_id', 400);
            }

            // Reject a non-scalar zone_id; (int) would silently coerce an array to 1.
            $zoneId = $this->inputInt($data, 'zone_id');
            if ($zoneId === null || $zoneId <= 0) {
                return $this->returnApiError('Invalid zone_id', 400);
            }

            if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
                return $scopeError;
            }

            if (!$this->domainRepository->zoneIdExists($zoneId)) {
                return $this->returnApiError('Zone not found', 404);
            }

            $this->zoneGroupService->addGroupToZone($zoneId, $groupId);

            return $this->returnApiResponse(null, true, 'Zone assigned successfully', 201);
        } catch (GroupNotFoundException $e) {
            return $this->returnApiError($e->getMessage(), 404);
        } catch (InvalidArgumentException $e) {
            // Domain validation refusal (group already owns this zone) - bad input, not a fault.
            return $this->returnApiError($e->getMessage(), 400);
        } catch (Exception $e) {
            return $this->handleException($e, 'GroupZonesController::assignZone', 'Failed to assign zone');
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
    #[OA\Response(response: 404, description: 'Zone or assignment not found')]
    private function unassignZone(): JsonResponse
    {
        if (!$this->apiPermissionService->userHasPermission($this->authenticatedUserId, Permission::PERM_USER_IS_UEBERUSER)) {
            return $this->returnApiError('Only administrators can unassign zones from groups', 403);
        }

        try {
            $groupId = (int)$this->pathParameters['id'];
            $zoneId = (int)($this->pathParameters['zone_id'] ?? 0);

            if ($zoneId === 0) {
                return $this->returnApiError('Invalid zone_id', 400);
            }

            if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
                return $scopeError;
            }

            if (!$this->domainRepository->zoneIdExists($zoneId)) {
                return $this->returnApiError('Zone not found', 404);
            }

            $result = $this->zoneGroupService->removeGroupFromZone($zoneId, $groupId);
            if ($result instanceof ZoneOwnershipRefusal) {
                return $this->returnApiError(self::refusalMessage($result), 400);
            }

            if (!$result) {
                return $this->returnApiError('Zone assignment not found', 404);
            }

            return $this->returnApiResponse(null, true, 'Zone unassigned successfully');
        } catch (GroupNotFoundException $e) {
            return $this->returnApiError($e->getMessage(), 404);
        } catch (Exception $e) {
            return $this->handleException($e, 'GroupZonesController::unassignZone', 'Failed to unassign zone');
        }
    }

    /**
     * The contract wording for a refused group removal; the hint names the kind
     * of owner the ownership mode still accepts.
     */
    private static function refusalMessage(ZoneOwnershipRefusal $refusal): string
    {
        return match ($refusal->code) {
            ZoneOwnershipRefusal::LAST_GROUP_GROUPS_ONLY => 'Cannot remove the last group: zone ownership mode is groups_only and requires at least one group. Add another group first.',
            ZoneOwnershipRefusal::USERS_ONLY_NO_USER_OWNERS => 'Cannot remove group: zone ownership mode is users_only and the zone has no user owners. Add a user owner first.',
            default => 'Cannot remove the last owner: this would leave the zone with no ownership. ' . match ($refusal->mode) {
                ZoneOwnershipModeService::MODE_GROUPS_ONLY => 'Add another group first (zone ownership mode is groups_only).',
                ZoneOwnershipModeService::MODE_USERS_ONLY => 'Add a user owner first (zone ownership mode is users_only).',
                default => 'Add another group or a user owner first.',
            },
        };
    }
}
