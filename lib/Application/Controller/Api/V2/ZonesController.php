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

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Controller\Api\V2\Resource\ZoneResource;
use Poweradmin\Application\Service\ZoneOwnershipInputFactory;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipInput;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Poweradmin\Domain\Enum\ZoneKind;

/**
 * /api/v2/zones: lists, creates, updates and deletes zones.
 */
class ZonesController extends PublicApiController
{
    private ZoneRepositoryInterface $zoneRepository;
    private DomainRepositoryInterface $domainRepository;
    private ZoneManagementService $zoneManagementService;
    private ApiPermissionService $apiPermissionService;
    private IPAddressValidator $ipAddressValidator;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = $this->services()->zoneRepository();
        $this->domainRepository = $this->services()->domainRepository();
        $this->apiPermissionService = $this->services()->apiPermissionService();
        $this->ipAddressValidator = new IPAddressValidator();

        $this->zoneManagementService = $this->createZoneManagementService();
    }

    /**
     * Handle zone-related requests
     */
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['id']) ? $this->getZone() : $this->listZones(),
            'POST' => $this->createZone(),
            'PUT' => $this->updateZone(),
            'DELETE' => $this->deleteZone(),
            default => $this->methodNotAllowed(['GET', 'POST', 'PUT', 'DELETE']),
        };

        $response->send();
        exit;
    }

    /**
     * List all zones accessible to the authenticated user
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/zones',
        operationId: 'v2ListZones',
        summary: 'List all zones',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones']
    )]
    #[OA\Parameter(
        name: 'page',
        description: 'Page number for pagination (optional, only used when per_page is specified)',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 1)
    )]
    #[OA\Parameter(
        name: 'per_page',
        description: 'Number of zones per page (optional, omit or set to 0 to return all zones)',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 0)
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
                    properties: [
                        new OA\Property(
                            property: 'zones',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'canonical_id', type: 'integer', example: 1, description: 'Zone id accepted by the other zone endpoints; equals id except for API-backend zones migrated from SQL mode'),
                                    new OA\Property(property: 'name', type: 'string', example: 'example.com'),
                                    new OA\Property(property: 'type', type: 'string', example: 'MASTER'),
                                    new OA\Property(property: 'created_at', type: 'string', example: '2025-01-01 12:00:00')
                                ],
                                type: 'object'
                            )
                        )
                    ],
                    type: 'object'
                ),
                new OA\Property(
                    property: 'pagination',
                    properties: [
                        new OA\Property(property: 'current_page', type: 'integer', example: 1),
                        new OA\Property(property: 'per_page', type: 'integer', example: 25),
                        new OA\Property(property: 'total', type: 'integer', example: 100),
                        new OA\Property(property: 'last_page', type: 'integer', example: 4)
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    private function listZones(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            // Get filter parameters
            $nameFilter = $this->request->query->get('name');

            // Get pagination parameters (defaults to returning all zones like PowerDNS and PowerDNS-Admin)
            $perPage = (int)$this->request->query->get('per_page', 0);

            // Get zone IDs that the user can view (null = all zones, [] = no zones, array = specific zones)
            $visibleZoneIds = $this->apiPermissionService->getUserVisibleZoneIds($userId);

            // Determine the actual userId to pass to repository (only needed for non-uber users)
            $filterUserId = ($visibleZoneIds !== null) ? $userId : null;

            // Narrow the visible set to the API key's zone scope, if the key is
            // restricted. An empty intersection yields an empty list, never a 403.
            $scope = $this->getApiKeyScope();
            if ($scope->hasZoneRestriction()) {
                $scopeIds = $scope->getZoneIds();
                $visibleZoneIds = ($visibleZoneIds === null)
                    ? $scopeIds
                    : array_values(array_intersect($visibleZoneIds, $scopeIds));
            }

            // Get total count for metadata (permission-filtered and name-filtered)
            $totalCount = $this->zoneRepository->getZoneCountFiltered($visibleZoneIds, $filterUserId, $nameFilter);

            // If user has no view permissions or name filter matches nothing, return empty result immediately
            if ($totalCount === 0) {
                return $this->returnApiResponse(['zones' => []], true, 'Zones retrieved successfully', 200, [
                    'meta' => ['timestamp' => date('Y-m-d H:i:s')],
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => $perPage > 0 ? $perPage : $totalCount,
                        'total' => 0,
                        'last_page' => 1
                    ]
                ]);
            }

            // If per_page is 0 or not specified, return all zones (compatible with PowerDNS/PowerDNS-Admin)
            if ($perPage === 0) {
                $zones = $this->zoneRepository->getAllZonesFiltered($visibleZoneIds, $filterUserId, $nameFilter);
                $page = 1;
                $lastPage = 1;
            } else {
                // Use pagination with permission and name filtering at database level
                $page = max(1, (int)$this->request->query->get('page', 1));
                $perPage = min(self::MAX_PAGE_SIZE, max(1, $perPage));
                $offset = ($page - 1) * $perPage;

                $zones = $this->zoneRepository->getAllZonesFiltered($visibleZoneIds, $filterUserId, $nameFilter, $offset, $perPage);
                $lastPage = (int)ceil($totalCount / $perPage);
            }

            // Format zone data
            $formattedZones = array_map(ZoneResource::summary(...), $zones);

            $responseData = [
                'meta' => [
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];

            // Only include pagination metadata if pagination was requested
            if ($perPage > 0) {
                $responseData['pagination'] = [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalCount,
                    'last_page' => $lastPage
                ];
            }

            return $this->returnApiResponse(['zones' => $formattedZones], true, 'Zones retrieved successfully', 200, $responseData);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZonesController::listZones', 'Failed to retrieve zones');
        }
    }

    /**
     * Get a specific zone by ID
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/zones/{id}',
        operationId: 'v2GetZone',
        summary: 'Get a specific zone',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones']
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'Zone ID',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Zone retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Zone retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(
                            property: 'zone',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'example.com'),
                                new OA\Property(property: 'type', type: 'string', example: 'MASTER'),
                                new OA\Property(property: 'created_at', type: 'string', example: '2025-01-01 12:00:00')
                            ],
                            type: 'object'
                        )
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Zone not found'
    )]
    private function getZone(): JsonResponse
    {
        try {
            $zoneId = $this->pathParameters['id'];
            $userId = $this->getAuthenticatedUserId();

            if (($scopeError = $this->enforceApiKeyZoneScope((int)$zoneId)) !== null) {
                return $scopeError;
            }

            // Get zone details
            $zone = $this->zoneRepository->getZoneById($zoneId);

            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to view this zone
            if (!$this->apiPermissionService->canViewZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to view this zone', 403);
            }

            // The description lives in the zones table, the rest in domains
            $formattedZone = ZoneResource::detail($zone, $this->zoneRepository->getZoneComment($zoneId));

            return $this->returnApiResponse(['zone' => $formattedZone], true, 'Zone retrieved successfully', 200);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZonesController::getZone', 'Failed to retrieve zone');
        }
    }

    /**
     * Create a new zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/v2/zones',
        operationId: 'v2CreateZone',
        description: 'Creates a new DNS zone with the provided information',
        summary: 'Create a new zone',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones']
    )]
    #[OA\RequestBody(
        description: 'Zone information for creating a new DNS zone',
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'name',
                    description: 'Zone name (FQDN)',
                    type: 'string',
                    example: 'example.com'
                ),
                new OA\Property(
                    property: 'type',
                    description: 'Zone type',
                    type: 'string',
                    enum: ['MASTER', 'SLAVE', 'NATIVE'],
                    example: 'MASTER'
                ),
                new OA\Property(
                    property: 'master',
                    description: 'Master server(s) for SLAVE zones. Supports: "192.0.2.1" (plain IP), ' .
                        '"192.0.2.1,192.0.2.2" (multiple), "192.0.2.1:5300" (with port). ' .
                        'IPv6 with port needs brackets: "[2001:db8::1]:5300"',
                    type: 'string',
                    example: '192.168.1.1:5300,192.168.1.2:5300'
                ),
                new OA\Property(
                    property: 'template',
                    description: 'Zone template ID to use (get IDs from GET /api/v2/zone-templates). Must be a global template, one owned by the caller, or the caller must be ueberuser.',
                    type: 'integer',
                    example: 1
                ),
                new OA\Property(
                    property: 'enable_dnssec',
                    description: 'Enable DNSSEC for this zone',
                    type: 'boolean',
                    example: false
                ),
                new OA\Property(
                    property: 'owner_user_id',
                    description: 'User ID to assign as zone owner. Defaults to the authenticated user when omitted '
                        . '(even if group_ids is supplied). Send an explicit null to opt out of user ownership and '
                        . 'create a group-only zone (requires zone_ownership_mode that allows groups and a non-empty '
                        . 'group_ids). Specifying a different user requires zone_content_edit_others permission '
                        . 'and the user must exist.',
                    type: 'integer',
                    example: 1,
                    nullable: true
                ),
                new OA\Property(
                    property: 'group_ids',
                    description: 'Optional list of group IDs to assign as zone owners. Requires user_is_ueberuser permission to assign arbitrary groups.',
                    type: 'array',
                    items: new OA\Items(type: 'integer'),
                    example: [2, 5]
                ),
                new OA\Property(
                    property: 'soa_edit_api',
                    description: 'Per-zone SOA-EDIT-API serial policy. Omit to apply the dns.soa_edit_api default; '
                        . 'send OFF to disable the policy for this zone. Values not offered by the server are '
                        . 'rejected with 400 for every zone type, unlike the web form which ignores them. The '
                        . 'available set is narrowed by the dns.soa_edit_api_options config list. A SLAVE zone '
                        . 'takes its serial from the primary, so the accepted value is not applied there.',
                    type: 'string',
                    enum: [...MetadataDefinitions::DEFINITIONS['SOA-EDIT-API']['options'], MetadataDefinitions::SOA_EDIT_API_OFF],
                    example: 'INCREASE'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Zone created successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Zone created successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'zone_id', type: 'integer', example: 123)
                    ],
                    type: 'object'
                ),
                new OA\Property(
                    property: 'meta',
                    properties: [
                        new OA\Property(property: 'timestamp', type: 'string', example: '2025-05-09 08:30:00')
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - validation failed',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Zone name is required'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden - owner or groups the caller may not assign',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'You do not have permission to create zones for other users'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Not found - owner_user_id or group_ids name an unknown user or group',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Unknown user ID: 42'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 409,
        description: 'Conflict - zone already exists',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Zone already exists'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    private function createZone(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            // A zone-scoped key is restricted to a fixed set of zones; creating a
            // new zone would fall outside that set, so it is not allowed.
            if ($this->getApiKeyScope()->hasZoneRestriction()) {
                return $this->returnApiError(
                    'Forbidden: this API key is restricted to specific zones and cannot create new zones',
                    403
                );
            }

            $input = $this->getValidatedJsonBody();

            if ($input === null) {
                return $this->returnApiError('Invalid JSON in request body', 400);
            }

            // Extract required parameters
            $domain = $this->inputString($input, 'name', '');
            $type = $this->inputString($input, 'type', 'MASTER');
            // Accept both a comma-separated string and the PowerDNS-style `masters`
            // array; the array form was previously dropped, creating a masterless SLAVE.
            if (isset($input['masters']) && is_array($input['masters'])) {
                $slaveMaster = implode(',', array_map('strval', $input['masters']));
            } else {
                $slaveMaster = $this->inputString($input, 'masters') ?? $this->inputString($input, 'master', '');
            }
            $enableDnssec = $this->inputBool($input, 'enable_dnssec', false);
            $description = $this->inputString($input, 'description', '');
            $account = $this->inputString($input, 'account', '');
            $soaEditApi = $this->inputString($input, 'soa_edit_api', '');
            if (
                $domain === null || $type === null || $slaveMaster === null || $enableDnssec === null
                || $description === null || $account === null || $soaEditApi === null
            ) {
                return $this->returnApiError('Invalid field types in request body', 400);
            }
            $type = strtoupper($type);
            $zoneTemplate = $this->inputTemplate($input);
            if ($zoneTemplate !== 'none' && !is_numeric($zoneTemplate)) {
                return $this->returnApiError('Template must be a numeric ID', 400);
            }
            // An omitted field and an empty one both mean "apply the config default".
            $soaEditApi = $soaEditApi === '' ? null : $soaEditApi;

            // Check if user has permission to create zones
            if (!$this->apiPermissionService->canCreateZone($userId, $type)) {
                return $this->returnApiError('You do not have permission to create zones of this type', 403);
            }

            $resolved = ZoneOwnershipInputFactory::fromJsonBody($input);
            if ($resolved instanceof ZoneOwnershipInput) {
                $resolved = $this->services()->zoneCreateOwnershipResolver()->resolve($resolved, $userId);
            }
            if ($resolved->hasError()) {
                return $this->returnApiError($resolved->error, $resolved->status);
            }
            $owner = $resolved->owner;
            $groupIds = $resolved->groupIds;

            // Match ZoneManagementService: when DNSSEC is disabled server-side, enable_dnssec
            // is a silent no-op, so the permission gate would just emit a misleading 403.
            $dnssecEnabledServerSide = (bool) $this->getConfig()->get('dnssec', 'enabled', false);
            if ($enableDnssec && $dnssecEnabledServerSide && !$this->apiPermissionService->canManageDnssecForNewZone($userId, $owner, $groupIds)) {
                return $this->returnApiError('You do not have permission to manage DNSSEC for this zone', 403);
            }

            // Use the zone management service to create zone
            $result = $this->zoneManagementService->createZone(
                $domain,
                $type,
                $owner,
                $slaveMaster,
                $zoneTemplate,
                $enableDnssec,
                $groupIds,
                $userId,
                $soaEditApi
            );

            if (!$result['success']) {
                return $this->returnApiError($result['message'], $result['status'], null, [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]);
            }

            $zoneId = $result['zone_id'];

            // Store description in zones table if provided
            if ($description !== '') {
                $this->zoneRepository->updateZoneComment($zoneId, $description);
            }

            // Update account in domains table if provided
            if ($account !== '') {
                $this->updateDomainAccount($zoneId, $account);
            }

            $this->services()->auditService()->logApiZoneAdd($zoneId, $domain, $type);

            // Signing is best effort; the outcome tells a client whether it happened
            $payload = ['zone_id' => $zoneId];
            if ($result['dnssec'] !== null) {
                $payload['dnssec'] = $result['dnssec']->outcome->value;
            }

            return $this->returnApiResponse($payload, true, 'Zone created successfully', 201);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZonesController::createZone', 'Failed to create zone');
        }
    }

    /**
     * Update domain account field
     *
     * @param int $zoneId Zone ID
     * @param string $account Account value
     * @return void
     */
    private function updateDomainAccount(int $zoneId, string $account): void
    {
        $backendProvider = $this->services()->dnsBackendProvider();
        $backendProvider->updateZoneAccount($zoneId, $account);
    }

    /**
     * Update an existing zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Put(
        path: '/v2/zones/{id}',
        operationId: 'v2UpdateZone',
        summary: 'Update an existing zone',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones']
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'Zone ID to update',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer', example: 123)
    )]
    #[OA\RequestBody(
        description: 'Zone update information',
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'name',
                    description: 'Zone name (FQDN)',
                    type: 'string',
                    example: 'example.com'
                ),
                new OA\Property(
                    property: 'type',
                    description: 'Zone type',
                    type: 'string',
                    enum: ['MASTER', 'SLAVE', 'NATIVE'],
                    example: 'MASTER'
                ),
                new OA\Property(
                    property: 'master',
                    description: 'Master IP address for SLAVE zones',
                    type: 'string',
                    example: '192.168.1.100'
                ),
                new OA\Property(
                    property: 'description',
                    description: 'Zone description or comment',
                    type: 'string',
                    example: 'Production DNS zone'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Zone updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Zone updated successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(
                            property: 'zone',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 123),
                                new OA\Property(property: 'name', type: 'string', example: 'example.com'),
                                new OA\Property(property: 'type', type: 'string', example: 'MASTER'),
                                new OA\Property(property: 'masters', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'account', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Production DNS zone'),
                                new OA\Property(property: 'created_at', type: 'string', nullable: true, example: '2024-01-01 00:00:00'),
                            ],
                            type: 'object'
                        )
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - validation failed',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid zone type'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Zone not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Zone not found'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    private function updateZone(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            // Get zone ID from path parameters
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            if ($zoneId <= 0) {
                return $this->returnApiError('Valid zone ID is required', 400);
            }

            if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
                return $scopeError;
            }

            // Confirm existence before permission, matching getZone()'s 404-before-403 order.
            if (!$this->domainRepository->zoneIdExists($zoneId)) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Metadata and description are gated separately below; this only rejects
            // callers who may write neither.
            $mayEditMeta = $this->apiPermissionService->canEditZoneMeta($userId, $zoneId);
            $mayEditContent = $this->apiPermissionService->hasZoneContentEditPermission($userId, $zoneId);
            if (!$mayEditMeta && !$mayEditContent) {
                return $this->returnApiError('You do not have permission to edit this zone', 403);
            }

            $input = $this->getValidatedJsonBody();
            if ($input === null) {
                return $this->returnApiError('Invalid JSON in request body', 400);
            }

            // Prepare updates array with only allowed fields
            $updates = [];
            $allowedFields = ['name', 'type', 'master'];

            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updates[$field] = $input[$field];
                }
            }

            // Validate zone type if provided
            if (isset($updates['type'])) {
                $updates['type'] = strtoupper($updates['type']);
                $validTypes = ZoneKind::basicValues();
                if (!in_array($updates['type'], $validTypes)) {
                    return $this->returnApiError('Invalid zone type. Must be one of: ' . implode(', ', $validTypes), 400);
                }
            }

            // Validate master servers format if provided
            if (isset($updates['master']) && !empty($updates['master'])) {
                $validation = $this->validateMasterServers($updates['master']);
                if (!$validation['valid']) {
                    return $this->returnApiError('Invalid master servers format: ' . $validation['message'], 400);
                }
                $updates['master'] = $validation['normalized'];
            }

            $hasDescriptionUpdate = array_key_exists('description', $input);

            if (empty($updates) && !$hasDescriptionUpdate) {
                return $this->returnApiError('No valid fields provided for update', 400);
            }

            $zone = $this->zoneRepository->getZoneById($zoneId);

            // Clients often resend every field; a value equal to the stored one changes
            // nothing, so only real changes are written and permission-gated.
            $updates = $this->dropUnchangedZoneFields($zone, $updates);

            // A zone becoming SLAVE needs a master; one that already is keeps its stored master
            if (isset($updates['type']) && $updates['type'] === 'SLAVE' && empty($updates['master'])) {
                return $this->returnApiError('Master IP address is required for SLAVE zones', 400);
            }

            // name/type/master are zone metadata, so they need the metadata permission
            // the web edit form requires - content-edit rights are not enough.
            if (!empty($updates) && !$mayEditMeta) {
                return $this->returnApiError('You do not have permission to edit this zone\'s settings', 403);
            }

            if ($hasDescriptionUpdate && !$mayEditContent) {
                return $this->returnApiError('You do not have permission to edit this zone', 403);
            }

            // Converting a zone is equivalent to creating one of the target type.
            if (isset($updates['type']) && !$this->apiPermissionService->canCreateZone($userId, $updates['type'])) {
                return $this->returnApiError('You do not have permission to change this zone to that type', 403);
            }

            // Use the zone management service to update zone (domains table fields)
            if (!empty($updates)) {
                $result = $this->zoneManagementService->updateZone($zoneId, $updates);

                if (!$result['success']) {
                    $statusCode = $result['status'] ?? 400;
                    return $this->returnApiError($result['message'], $statusCode);
                }
            }

            // Update description after main update succeeds (stored in zones table, not domains)
            if ($hasDescriptionUpdate) {
                $this->zoneRepository->updateZoneComment($zoneId, (string)$input['description']);
            }

            // Return updated zone data
            $zone = $this->zoneRepository->getZoneById($zoneId);
            $formattedZone = ZoneResource::detail($zone, $this->zoneRepository->getZoneComment($zoneId));

            return $this->returnApiResponse(['zone' => $formattedZone], true, 'Zone updated successfully', 200);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZonesController::updateZone', 'Failed to update zone');
        }
    }

    /**
     * Delete a zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Delete(
        path: '/v2/zones/{id}',
        operationId: 'v2DeleteZone',
        summary: 'Delete a zone',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones']
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'Zone ID to delete',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer', example: 123)
    )]
    #[OA\Response(
        response: 204,
        description: 'Zone deleted successfully'
    )]
    #[OA\Response(
        response: 404,
        description: 'Zone not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Zone not found'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    private function deleteZone(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            // Get zone ID from path parameters
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            if ($zoneId <= 0) {
                return $this->returnApiError('Valid zone ID is required', 400);
            }

            if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
                return $scopeError;
            }

            // Confirm existence before permission, matching getZone()'s 404-before-403 order.
            if (!$this->domainRepository->zoneIdExists($zoneId)) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to delete this zone
            if (!$this->apiPermissionService->canDeleteZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to delete this zone', 403);
            }

            if ($this->apiPermissionService->zoneDeleteRequiresApproval($userId, $zoneId)) {
                return $this->returnApiError(self::CHANGE_REQUEST_REQUIRED, 403);
            }

            // Capture zone name before deletion
            $zone = $this->zoneRepository->getZoneById($zoneId);
            $zoneName = $zone['name'] ?? 'unknown';

            // Use the zone management service to delete zone
            $result = $this->zoneManagementService->deleteZone($zoneId);

            if (!$result['success']) {
                $statusCode = $result['status'] ?? 400;
                return $this->returnApiError($result['message'], $statusCode);
            }

            $this->services()->auditService()->logApiZoneDelete($zoneId, $zoneName);

            return $this->returnApiResponse(null, true, 'Zone deleted successfully', 204);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZonesController::deleteZone', 'Failed to delete zone');
        }
    }

    /**
     * Remove name, type and master entries that equal the zone's stored values.
     *
     * @param array<string, mixed>|null $zone Stored zone row, null when it does not exist
     * @param array<string, mixed> $updates
     * @return array<string, mixed>
     */
    private function dropUnchangedZoneFields(?array $zone, array $updates): array
    {
        if ($zone === null || empty($updates)) {
            return $updates;
        }

        if (
            isset($updates['name'])
            && rtrim((string)$updates['name'], '.') === rtrim((string)$zone['name'], '.')
        ) {
            unset($updates['name']);
        }

        if (isset($updates['type']) && strtoupper((string)($zone['type'] ?? '')) === $updates['type']) {
            unset($updates['type']);
        }

        if (
            isset($updates['master'])
            && $this->normalizeMasterList((string)$updates['master']) === $this->normalizeMasterList((string)($zone['master'] ?? ''))
        ) {
            unset($updates['master']);
        }

        return $updates;
    }

    private function normalizeMasterList(string $masters): string
    {
        return implode(',', array_filter(array_map('trim', explode(',', $masters)), 'strlen'));
    }

    /**
     * Validate master servers format
     *
     * Supports both formats for backward compatibility:
     * - Simple IP list: "192.0.2.1,192.0.2.2"
     * - IP with port: "192.0.2.1:5300,192.0.2.2:5300"
     * - Mixed: "192.0.2.1,192.0.2.2:5300" (though not recommended)
     *
     * IPv6 addresses must be enclosed in brackets when using port notation:
     * - "[2001:db8::1]:5300"
     *
     * @param string $masters Comma-separated list of master servers
     * @return array ['valid' => bool, 'message' => string, 'normalized' => string]
     */
    private function validateMasterServers(string $masters): array
    {
        if (trim($masters) === '') {
            return ['valid' => true, 'message' => '', 'normalized' => ''];
        }

        $result = $this->ipAddressValidator->validateMultipleIPs($masters);

        if (!$result->isValid()) {
            $errors = $result->getErrors();
            return [
                'valid' => false,
                'message' => implode('; ', $errors),
                'normalized' => ''
            ];
        }

        $validatedServers = $result->getData();

        if (empty($validatedServers)) {
            return [
                'valid' => false,
                'message' => 'No valid master servers provided',
                'normalized' => ''
            ];
        }

        return [
            'valid' => true,
            'message' => '',
            'normalized' => implode(',', $validatedServers)
        ];
    }
}
