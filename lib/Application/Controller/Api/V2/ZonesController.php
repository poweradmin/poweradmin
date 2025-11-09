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
 * RESTful API controller for zone operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Exception;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZonesController extends PublicApiController
{
    private DbZoneRepository $zoneRepository;
    private RecordRepository $recordRepository;
    private RecordManagerInterface $recordManager;
    private ZoneManagementService $zoneManagementService;
    private ApiPermissionService $permissionService;
    private IPAddressValidator $ipAddressValidator;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $this->recordRepository = new RecordRepository($this->db, $this->getConfig());
        $this->permissionService = new ApiPermissionService($this->db);
        $this->ipAddressValidator = new IPAddressValidator();

        // Initialize services using factory
        $validationService = DnsServiceFactory::createDnsRecordValidationService($this->db, $this->getConfig());
        $soaRecordManager = new SOARecordManager($this->db, $this->getConfig());
        $domainRepository = new DomainRepository($this->db, $this->getConfig());
        $this->recordManager = new RecordManager(
            $this->db,
            $this->getConfig(),
            $validationService,
            $soaRecordManager,
            $domainRepository
        );

        // Initialize zone management service
        $this->zoneManagementService = new ZoneManagementService(
            $this->zoneRepository,
            $this->getConfig(),
            $this->db
        );
    }

    /**
     * Handle zone-related requests
     */
    #[\Override]
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['id']) ? $this->getZone() : $this->listZones(),
            'POST' => $this->createZone(),
            'PUT' => $this->updateZone(),
            'DELETE' => $this->deleteZone(),
            default => $this->returnApiError('Method not allowed', 405),
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
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'name', type: 'string', example: 'example.com'),
                            new OA\Property(property: 'type', type: 'string', example: 'MASTER'),
                            new OA\Property(property: 'created_at', type: 'string', example: '2025-01-01 12:00:00')
                        ],
                        type: 'object'
                    )
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
            $visibleZoneIds = $this->permissionService->getUserVisibleZoneIds($userId);

            // Determine the actual userId to pass to repository (only needed for non-uber users)
            $filterUserId = ($visibleZoneIds !== null) ? $userId : null;

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
                $perPage = min(10000, max(1, $perPage)); // Allow up to 10k per page
                $offset = ($page - 1) * $perPage;

                $zones = $this->zoneRepository->getAllZonesFiltered($visibleZoneIds, $filterUserId, $nameFilter, $offset, $perPage);
                $lastPage = (int)ceil($totalCount / $perPage);
            }

            // Format zone data
            $formattedZones = array_map(function ($zone) {
                return [
                    'id' => (int)$zone['id'],
                    'name' => $zone['name'],
                    'type' => $zone['type'] ?? 'MASTER',
                    'created_at' => $zone['created_at'] ?? null
                ];
            }, $zones);

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
        } catch (Exception $e) {
            return $this->returnApiError('Failed to retrieve zones: ' . $e->getMessage(), 500);
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

            // Get zone details
            $zone = $this->zoneRepository->getZoneById($zoneId);

            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to view this zone
            if (!$this->permissionService->canViewZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to view this zone', 403);
            }

            // Get zone comment/description from zones table
            $comment = $this->zoneRepository->getZoneComment($zoneId);

            // Get zone account and masters from domains table
            $account = $zone['account'] ?? '';
            $masters = $zone['master'] ?? '';

            $formattedZone = [
                'id' => (int)$zone['id'],
                'name' => $zone['name'],
                'type' => $zone['type'] ?? 'MASTER',
                'created_at' => $zone['created_at'] ?? null
            ];

            // Add optional fields only if they have values
            if ($masters !== '') {
                $formattedZone['masters'] = $masters;
            }
            if ($account !== '') {
                $formattedZone['account'] = $account;
            }
            if ($comment !== null && $comment !== '') {
                $formattedZone['description'] = $comment;
            }

            return $this->returnApiResponse(['zone' => $formattedZone], true, 'Zone retrieved successfully', 200);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to retrieve zone: ' . $e->getMessage(), 500);
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
                    description: 'Zone template name to use',
                    type: 'string',
                    example: 'default'
                ),
                new OA\Property(
                    property: 'enable_dnssec',
                    description: 'Enable DNSSEC for this zone',
                    type: 'boolean',
                    example: false
                ),
                new OA\Property(
                    property: 'owner_user_id',
                    description: 'User ID to assign as zone owner (defaults to authenticated user). Specifying different user requires zone_content_edit_others permission.',
                    type: 'integer',
                    example: 1
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
            $input = json_decode($this->request->getContent(), true);

            if (!$input) {
                return $this->returnApiError('Invalid JSON in request body', 400);
            }

            // Extract required parameters
            $domain = $input['name'] ?? '';
            $type = strtoupper($input['type'] ?? 'MASTER');
            $slaveMaster = $input['masters'] ?? $input['master'] ?? '';
            $zoneTemplate = $input['template'] ?? 'none';
            $enableDnssec = $input['enable_dnssec'] ?? false;

            // Extract optional parameters
            $description = $input['description'] ?? '';
            $account = $input['account'] ?? '';

            // Validate master servers format if provided
            if (!empty($slaveMaster)) {
                $validation = $this->validateMasterServers($slaveMaster);
                if (!$validation['valid']) {
                    return $this->returnApiError('Invalid master servers format: ' . $validation['message'], 400);
                }
                $slaveMaster = $validation['normalized'];
            }

            // Check if user has permission to create zones
            if (!$this->permissionService->canCreateZone($userId, $type)) {
                return $this->returnApiError('You do not have permission to create zones of this type', 403);
            }

            // Default owner to authenticated user if not specified
            // Allow specifying different owner only if user has zone_content_edit_others permission
            $owner = (int)($input['owner_user_id'] ?? $userId);

            if ($owner !== $userId) {
                // User wants to create zone for a different owner - check if they have permission
                if (
                    !$this->permissionService->userHasPermission($userId, 'user_is_ueberuser') &&
                    !$this->permissionService->userHasPermission($userId, 'zone_content_edit_others')
                ) {
                    return $this->returnApiError('You do not have permission to create zones for other users', 403);
                }
            }

            // Use the zone management service to create zone
            $result = $this->zoneManagementService->createZone(
                $domain,
                $type,
                $owner,
                $slaveMaster,
                $zoneTemplate,
                $enableDnssec
            );

            if (!$result['success']) {
                $statusCode = match ($result['message']) {
                    'Domain already exists' => 409,
                    default => 400
                };

                return $this->returnApiError($result['message'], $statusCode, null, [
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

            return $this->returnApiResponse(
                ['zone_id' => $zoneId],
                true,
                $result['message'] ?? 'Zone created successfully',
                201
            );
        } catch (Exception $e) {
            return $this->returnApiError('Failed to create zone: ' . $e->getMessage(), 500);
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
        $tableNameService = new TableNameService($this->getConfig());
        $domains_table = $tableNameService->getTable(PdnsTable::DOMAINS);

        $query = "UPDATE $domains_table SET account = :account WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':account' => $account,
            ':id' => $zoneId
        ]);
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
                new OA\Property(property: 'data', type: 'null')
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

            // Check if user has permission to edit this zone
            if (!$this->permissionService->canEditZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to edit this zone', 403);
            }

            $input = json_decode($this->request->getContent(), true);
            if (!$input) {
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
                $validTypes = ['MASTER', 'SLAVE', 'NATIVE'];
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

            // For SLAVE zones, ensure master IP is provided
            if (isset($updates['type']) && $updates['type'] === 'SLAVE' && empty($updates['master'])) {
                return $this->returnApiError('Master IP address is required for SLAVE zones', 400);
            }

            if (empty($updates)) {
                return $this->returnApiError('No valid fields provided for update', 400);
            }

            // Use the zone management service to update zone
            $result = $this->zoneManagementService->updateZone($zoneId, $updates);

            if (!$result['success']) {
                $statusCode = match ($result['message']) {
                    'Zone not found' => 404,
                    default => 400
                };
                return $this->returnApiError($result['message'], $statusCode);
            }

            return $this->returnApiResponse(null, true, 'Zone updated successfully', 200);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to update zone: ' . $e->getMessage(), 500);
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
        description: 'Zone deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Zone deleted successfully'),
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
    private function deleteZone(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            // Get zone ID from path parameters
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            if ($zoneId <= 0) {
                return $this->returnApiError('Valid zone ID is required', 400);
            }

            // Check if user has permission to delete this zone
            if (!$this->permissionService->canDeleteZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to delete this zone', 403);
            }

            // Use the zone management service to delete zone
            $result = $this->zoneManagementService->deleteZone($zoneId);

            if (!$result['success']) {
                $statusCode = match ($result['message']) {
                    'Zone not found' => 404,
                    default => 400
                };
                return $this->returnApiError($result['message'], $statusCode);
            }

            return $this->returnApiResponse(null, true, 'Zone deleted successfully', 204);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to delete zone: ' . $e->getMessage(), 500);
        }
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
