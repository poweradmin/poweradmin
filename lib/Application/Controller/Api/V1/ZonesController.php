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

namespace Poweradmin\Application\Controller\Api\V1;

use Exception;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Domain\Service\ZoneManagementService;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZonesController extends PublicApiController
{
    private DbZoneRepository $zoneRepository;
    private RecordRepository $recordRepository;
    private RecordManagerInterface $recordManager;
    private ZoneManagementService $zoneManagementService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $this->recordRepository = new RecordRepository($this->db, $this->getConfig());

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
        path: '/v1/zones',
        operationId: 'v1ListZones',
        summary: 'List all zones',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones']
    )]
    #[OA\Parameter(
        name: 'page',
        description: 'Page number for pagination',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 1)
    )]
    #[OA\Parameter(
        name: 'per_page',
        description: 'Number of zones per page',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 25)
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
            // Get pagination parameters
            $page = max(1, (int)$this->request->query->get('page', 1));
            $perPage = min(100, max(1, (int)$this->request->query->get('per_page', 25)));
            $offset = ($page - 1) * $perPage;

            // Get zones accessible to current user
            $zones = $this->zoneRepository->getAllZones($offset, $perPage);
            $totalCount = $this->zoneRepository->getZoneCount();

            // Format zone data
            $formattedZones = array_map(function ($zone) {
                return [
                    'id' => (int)$zone['id'],
                    'name' => $zone['name'],
                    'type' => $zone['type'] ?? 'MASTER',
                    'created_at' => $zone['created_at'] ?? null
                ];
            }, $zones);

            return $this->returnApiResponse($formattedZones, true, 'Zones retrieved successfully', 200, [
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalCount,
                    'last_page' => ceil($totalCount / $perPage)
                ],
                'meta' => [
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
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
        path: '/v1/zones/{id}',
        operationId: 'v1GetZone',
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
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'name', type: 'string', example: 'example.com'),
                        new OA\Property(property: 'type', type: 'string', example: 'MASTER'),
                        new OA\Property(property: 'created_at', type: 'string', example: '2025-01-01 12:00:00')
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

            // Get zone details
            $zone = $this->zoneRepository->getZoneById($zoneId);

            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            $formattedZone = [
                'id' => (int)$zone['id'],
                'name' => $zone['name'],
                'type' => $zone['type'] ?? 'MASTER',
                'created_at' => $zone['created_at'] ?? null
            ];

            return $this->returnApiResponse($formattedZone, true, 'Zone retrieved successfully', 200, [
                'meta' => [
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
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
        path: '/v1/zones',
        operationId: 'v1CreateZone',
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
                    description: 'Master server IP (required for SLAVE zones)',
                    type: 'string',
                    example: '192.168.1.1'
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
                    description: 'User ID to assign as zone owner (required)',
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
            $input = json_decode($this->request->getContent(), true);

            if (!$input) {
                return $this->returnApiError('Invalid JSON in request body', 400);
            }

            // Extract required parameters
            $domain = $input['name'] ?? '';
            $type = strtoupper($input['type'] ?? 'MASTER');
            $slaveMaster = $input['master'] ?? '';
            $zoneTemplate = $input['template'] ?? 'none';
            $enableDnssec = $input['enable_dnssec'] ?? false;

            // Require explicit owner for API requests (stateless)
            if (!isset($input['owner_user_id'])) {
                return $this->returnApiError('owner_user_id is required for zone creation', 400);
            }
            $owner = (int)$input['owner_user_id'];

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

            return $this->returnApiResponse(
                ['zone_id' => $result['zone_id']],
                true,
                $result['message'],
                201,
                [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]
            );
        } catch (Exception $e) {
            return $this->returnApiError('Failed to create zone: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an existing zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Put(
        path: '/v1/zones/{id}',
        operationId: 'v1UpdateZone',
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
            // Get zone ID from path parameters
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            if ($zoneId <= 0) {
                return $this->returnApiError('Valid zone ID is required', 400);
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

            return $this->returnApiResponse(null, true, 'Zone updated successfully', 200, [
                'meta' => [
                    'zone_id' => $zoneId,
                    'updated_fields' => array_keys($updates),
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
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
        path: '/v1/zones/{id}',
        operationId: 'v1DeleteZone',
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
            // Get zone ID from path parameters
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            if ($zoneId <= 0) {
                return $this->returnApiError('Valid zone ID is required', 400);
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

            return $this->returnApiResponse(null, true, 'Zone deleted successfully', 204, [
                'meta' => [
                    'zone_id' => $zoneId,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to delete zone: ' . $e->getMessage(), 500);
        }
    }
}
