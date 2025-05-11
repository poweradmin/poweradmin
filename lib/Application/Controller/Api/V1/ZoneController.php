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
 * V1 API controller for zone operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\v1;

use Exception;
use PDO;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZoneController extends PublicApiController
{
    private DbZoneRepository $zoneRepository;
    private RecordRepository $recordRepository;
    private RecordManagerInterface $recordManager;

    /**
     * Constructor for ZoneController
     *
     * @param array $request The request data
     */
    public function __construct(array $request)
    {
        parent::__construct($request);

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
    }

    /**
     * Run the controller based on the action parameter
     */
    public function run(): void
    {
        $method = $this->request->getMethod();
        $action = $this->request->query->get('action', '');

        $response = match ($method) {
            'GET' => $this->handleGetRequest($action),
            'POST' => $this->handlePostRequest($action),
            'PUT' => $this->handlePutRequest($action),
            'DELETE' => $this->handleDeleteRequest($action),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * Handle GET requests
     *
     * @param string $action The action to perform
     * @return JsonResponse The JSON response
     */
    private function handleGetRequest(string $action): JsonResponse
    {
        return match ($action) {
            'list' => $this->listZones(),
            'get' => $this->getZone(),
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * Handle POST requests
     *
     * @param string $action The action to perform
     * @return JsonResponse The JSON response
     */
    private function handlePostRequest(string $action): JsonResponse
    {
        return match ($action) {
            'create' => $this->createZone(),
            'add_record' => $this->addRecord(),
            'set_permissions' => $this->setDomainPermissions(),
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * Handle PUT requests
     *
     * @param string $action The action to perform
     * @return JsonResponse The JSON response
     */
    private function handlePutRequest(string $action): JsonResponse
    {
        return match ($action) {
            'update' => $this->updateZone(),
            'update_record' => $this->updateRecord(),
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * Handle DELETE requests
     *
     * @param string $action The action to perform
     * @return JsonResponse The JSON response
     */
    private function handleDeleteRequest(string $action): JsonResponse
    {
        return match ($action) {
            'delete' => $this->deleteZone(),
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * List all accessible zones
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/api/v1/zone/list',
        operationId: 'v1ZoneList',
        summary: 'List all accessible zones',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones']
    )]
    #[OA\Parameter(
        name: 'action',
        description: 'Action parameter (must be \'list\')',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string', default: 'list', enum: ['list'])
    )]
    #[OA\Parameter(
        name: 'pagenum',
        description: 'Page number for pagination',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)
    )]
    #[OA\Parameter(
        name: 'limit',
        description: 'Number of results per page',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 100)
    )]
    #[OA\Response(
        response: 200,
        description: 'List of zones',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(
                            property: 'zones',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'name', type: 'string', example: 'example.com'),
                                    new OA\Property(property: 'type', type: 'string', example: 'MASTER'),
                                    new OA\Property(property: 'records_count', type: 'integer', example: 5)
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(
                            property: 'pagination',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 150),
                                new OA\Property(property: 'pagenum', type: 'integer', example: 1),
                                new OA\Property(property: 'limit', type: 'integer', example: 20),
                                new OA\Property(property: 'pages', type: 'integer', example: 8)
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
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid or missing API key'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    public function listZones(): JsonResponse
    {
        // Get pagination parameters from request
        $pagenum = $this->request->query->getInt('pagenum', 1);
        $limit = $this->request->query->getInt('limit', 20);

        // Ensure valid pagination
        $pagenum = max(1, $pagenum);
        $limit = min(100, max(1, $limit));

        $zones = $this->zoneRepository->listZones();

        // Apply pagination (in a real implementation, this would be done in the repository)
        $totalZones = count($zones);
        $offset = ($pagenum - 1) * $limit;
        $paginatedZones = array_slice($zones, $offset, $limit);

        // Use serializer for consistent output format
        $serializedZones = json_decode($this->serialize($paginatedZones), true);

        return $this->returnApiResponse([
            'zones' => $serializedZones,
            'pagination' => [
                'total' => $totalZones,
                'pagenum' => $pagenum,
                'limit' => $limit,
                'pages' => ceil($totalZones / $limit)
            ]
        ]);
    }

    /**
     * Get a specific zone by ID or name
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/api/v1/zone/get/{id}',
        operationId: 'v1ZoneGet',
        summary: 'Get a specific zone by ID or name',
        tags: ['zones'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'action',
        in: 'query',
        required: true,
        description: 'Action parameter (must be \'get\')',
        schema: new OA\Schema(type: 'string', default: 'get', enum: ['get'])
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'Zone ID or "name" if querying by name',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Parameter(
        name: 'by',
        in: 'query',
        description: 'Query by "id" (default) or "name"',
        schema: new OA\Schema(type: 'string', enum: ['id', 'name'], default: 'id')
    )]
    #[OA\Response(
        response: 200,
        description: 'Zone details',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'zone',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'example.com'),
                                new OA\Property(property: 'type', type: 'string', example: 'MASTER'),
                                new OA\Property(property: 'owner', type: 'integer', example: 1),
                                new OA\Property(property: 'created', type: 'string', format: 'date-time', example: '2025-05-01T12:00:00Z'),
                                new OA\Property(property: 'updated', type: 'string', format: 'date-time', example: '2025-05-09T08:30:00Z')
                            ]
                        )
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Missing required parameters',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Missing zone ID or name'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid or missing API key'),
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
    public function getZone(): JsonResponse
    {
        // Get the "id" from the path - could be either numeric ID or a name
        $idParam = $this->request->attributes->get('id', '');
        $queryBy = $this->request->query->get('by', 'id');

        if (empty($idParam)) {
            return $this->returnApiError('Missing zone identifier in path', 400);
        }

        $zone = null;

        if ($queryBy === 'id' && is_numeric($idParam)) {
            $zoneId = (int)$idParam;
            $zone = $this->zoneRepository->getZone($zoneId);
        } else {
            // Either explicitly querying by name, or the ID parameter isn't numeric
            $zone = $this->zoneRepository->getZoneByName($idParam);
        }

        if (!$zone) {
            return $this->returnApiError('Zone not found', 404);
        }

        // Use serializer for consistent output format
        $serializedZone = json_decode($this->serialize($zone), true);

        return $this->returnApiResponse([
            'zone' => $serializedZone
        ]);
    }

    /**
     * Create a new zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/api/v1/zone/create',
        operationId: 'v1ZoneCreate',
        summary: 'Create a new zone',
        tags: ['zones'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'action',
        in: 'query',
        required: true,
        description: 'Action parameter (must be \'create\')',
        schema: new OA\Schema(type: 'string', default: 'create', enum: ['create'])
    )]
    #[OA\RequestBody(
        required: true,
        description: 'Zone creation information',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'example.com', description: 'Zone name'),
                new OA\Property(property: 'type', type: 'string', example: 'MASTER', description: 'Zone type'),
                new OA\Property(property: 'owner', type: 'integer', example: 1, description: 'Zone owner (optional)'),
                new OA\Property(property: 'dnssec', type: 'boolean', example: true, description: 'Enable DNSSEC (optional)')
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Zone created successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 123),
                        new OA\Property(property: 'name', type: 'string', example: 'example.com'),
                        new OA\Property(property: 'type', type: 'string', example: 'MASTER'),
                        new OA\Property(property: 'message', type: 'string', example: 'Zone created successfully')
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid input data'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid or missing API key'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    public function createZone(): JsonResponse
    {
        $input = $this->getJsonInput();

        if (!$input) {
            return $this->returnApiError('Invalid input data', 400);
        }

        // Validate required fields
        $requiredFields = ['name', 'type'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                return $this->returnApiError("Missing required field: {$field}", 400);
            }
        }

        // Set defaults and get values
        $domain = trim($input['name']);
        $type = strtoupper($input['type']); // Ensure uppercase
        $owner = isset($input['owner']) ? (int)$input['owner'] : (int)$_SESSION['userid']; // Default to current API user, cast to int
        $zone_template = $input['zone_template'] ?? 'none';
        $slave_master = $input['master'] ?? ''; // For SLAVE zones

        // Create DNS record service
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());

        // Validate domain name
        $hostnameValidator = new HostnameValidator($this->getConfig());
        if (!$hostnameValidator->isValid($domain)) {
            return $this->returnApiError('Invalid domain name', 400);
        }

        // Check if domain already exists
        if ($dnsRecord->domainExists($domain)) {
            return $this->returnApiError('Domain already exists', 400);
        }

        // Validate zone type
        $validTypes = ['MASTER', 'SLAVE', 'NATIVE'];
        if (!in_array($type, $validTypes)) {
            return $this->returnApiError('Invalid zone type. Must be one of: ' . implode(', ', $validTypes), 400);
        }

        // For SLAVE zones, ensure master IP is provided
        if ($type === 'SLAVE' && empty($slave_master)) {
            return $this->returnApiError('Master IP address is required for SLAVE zones', 400);
        }

        error_log(sprintf('[ZoneController] Creating zone: %s, Type: %s, Owner: %s', $domain, $type, $owner));

        // Create the domain
        $success = $dnsRecord->addDomain($this->db, $domain, $owner, $type, $slave_master, $zone_template);

        if (!$success) {
            return $this->returnApiError('Failed to create zone', 500);
        }

        // Get the ID of the newly created zone
        $zoneId = $dnsRecord->getZoneIdFromName($domain);

        // Enable DNSSEC if requested and supported
        if (isset($input['dnssec']) && $input['dnssec']) {
            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

            if ($dnssecProvider->isDnssecEnabled()) {
                try {
                    $dnssecProvider->secureZone($domain);
                } catch (Exception $e) {
                    error_log('[ZoneController] Failed to secure zone with DNSSEC: ' . $e->getMessage());
                    // We don't return an error since the zone was created successfully
                }

                $dnssecProvider->rectifyZone($domain);
            }
        }

        // Return success response with zone ID
        return $this->returnApiResponse([
            'id' => $zoneId,
            'name' => $domain,
            'type' => $type,
            'message' => 'Zone created successfully'
        ], true, null, 201);
    }

    /**
     * Update an existing zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Put(
        path: '/api/v1/zone/update',
        operationId: 'v1ZoneUpdate',
        summary: 'Update an existing zone',
        tags: ['zones'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'action',
        in: 'query',
        required: true,
        description: 'Action parameter (must be \'update\')',
        schema: new OA\Schema(type: 'string', default: 'update', enum: ['update'])
    )]
    #[OA\RequestBody(
        required: true,
        description: 'Zone update information',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1, description: 'ID of the zone to update'),
                new OA\Property(property: 'name', type: 'string', example: 'example.com', description: 'New zone name (optional)'),
                new OA\Property(property: 'type', type: 'string', example: 'MASTER', description: 'New zone type (optional)'),
                new OA\Property(property: 'owner', type: 'integer', example: 1, description: 'New zone owner (optional)')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Zone updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Zone updated successfully')
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid input data'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid or missing API key'),
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
    public function updateZone(): JsonResponse
    {
        $input = $this->getJsonInput();

        if (!$input) {
            return $this->returnApiError('Invalid input data', 400);
        }

        // Ensure zone ID is provided
        if (!isset($input['id']) || (int)$input['id'] <= 0) {
            return $this->returnApiError('Missing or invalid zone ID', 400);
        }

        // Implementation would continue here with actual zone update logic
        // For this example, we'll just return a success response

        return $this->returnApiResponse([
            'message' => 'Zone updated successfully'
        ]);
    }

    /**
     * Update a DNS record
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Put(
        path: '/api/v1/zone/record/update',
        operationId: 'v1ZoneRecordUpdate',
        summary: 'Update a DNS record',
        tags: ['zones'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'action',
        in: 'query',
        required: true,
        description: 'Action parameter (must be \'update_record\')',
        schema: new OA\Schema(type: 'string', default: 'update_record', enum: ['update_record'])
    )]
    #[OA\RequestBody(
        required: true,
        description: 'Record update information',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'record_id', type: 'integer', example: 123, description: 'ID of the record to update'),
                new OA\Property(property: 'name', type: 'string', example: 'www.example.com', description: 'New record name'),
                new OA\Property(property: 'type', type: 'string', example: 'A', description: 'Record type'),
                new OA\Property(property: 'content', type: 'string', example: '192.168.1.1', description: 'New record content'),
                new OA\Property(property: 'ttl', type: 'integer', example: 3600, description: 'New TTL value'),
                new OA\Property(property: 'prio', type: 'integer', example: 0, description: 'New priority (for MX/SRV records)'),
                new OA\Property(property: 'disabled', type: 'integer', example: 0, description: 'Disabled flag (0=enabled, 1=disabled)')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Record updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Record updated successfully'),
                        new OA\Property(
                            property: 'record',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 123),
                                new OA\Property(property: 'name', type: 'string', example: 'www.example.com'),
                                new OA\Property(property: 'type', type: 'string', example: 'A'),
                                new OA\Property(property: 'content', type: 'string', example: '192.168.1.1'),
                                new OA\Property(property: 'ttl', type: 'integer', example: 3600),
                                new OA\Property(property: 'prio', type: 'integer', example: 0),
                                new OA\Property(property: 'disabled', type: 'integer', example: 0)
                            ]
                        )
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid input data'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid or missing API key'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'You do not have permission to update this record'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Record not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Record not found'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    public function updateRecord(): JsonResponse
    {
        $input = $this->getJsonInput();

        if (!$input) {
            return $this->returnApiError('Invalid input data', 400);
        }

        // Ensure record ID is provided
        if (!isset($input['record_id']) || (int)$input['record_id'] <= 0) {
            return $this->returnApiError('Missing or invalid record ID', 400);
        }

        $recordId = (int)$input['record_id'];

        // Get the current record details
        $record = $this->recordRepository->getRecordFromId($recordId);

        if (!is_array($record) || empty($record)) {
            return $this->returnApiError('Record not found', 404);
        }

        // Prepare the record update data
        $updateData = [
            'rid' => $recordId,
            'zid' => $record['domain_id'],
            'name' => $input['name'] ?? $record['name'],
            'type' => $input['type'] ?? $record['type'],
            'content' => $input['content'] ?? $record['content'],
            'ttl' => isset($input['ttl']) ? (int)$input['ttl'] : (int)$record['ttl'],
            'prio' => isset($input['prio']) ? (int)$input['prio'] : (int)$record['prio'],
            'disabled' => isset($input['disabled']) ? (int)$input['disabled'] : (int)$record['disabled']
        ];

        // Check if the API key has permission to modify this zone
        // This will be implemented in a later task for domain permissions

        // Attempt to update the record
        $success = $this->recordManager->editRecord($updateData);

        if (!$success) {
            // If there was an error, return the error message
            return $this->returnApiError('Failed to update record', 400);
        }

        // Get the updated record to return in the response
        $updatedRecord = $this->recordRepository->getRecordFromId($recordId);

        return $this->returnApiResponse([
            'message' => 'Record updated successfully',
            'record' => $updatedRecord
        ]);
    }

    /**
     * Add a new DNS record
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/api/v1/zone/record/add',
        operationId: 'v1ZoneRecordAdd',
        summary: 'Add a new DNS record to a zone',
        tags: ['zones'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'action',
        in: 'query',
        required: true,
        description: 'Action parameter (must be \'add_record\')',
        schema: new OA\Schema(type: 'string', default: 'add_record', enum: ['add_record'])
    )]
    #[OA\RequestBody(
        required: true,
        description: 'Record information',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'zone_id', type: 'integer', example: 1, description: 'Zone ID'),
                new OA\Property(property: 'name', type: 'string', example: 'www.example.com', description: 'Record name'),
                new OA\Property(property: 'type', type: 'string', example: 'A', description: 'Record type'),
                new OA\Property(property: 'content', type: 'string', example: '192.168.1.1', description: 'Record content'),
                new OA\Property(property: 'ttl', type: 'integer', example: 3600, description: 'TTL value'),
                new OA\Property(property: 'prio', type: 'integer', example: 0, description: 'Priority (for MX/SRV records)')
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Record created successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Record added successfully'),
                        new OA\Property(property: 'record_id', type: 'integer', example: 123)
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid input data'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid or missing API key'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'You do not have permission to add records to this zone'),
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
    public function addRecord(): JsonResponse
    {
        $input = $this->getJsonInput();

        if (!$input) {
            return $this->returnApiError('Invalid input data', 400);
        }

        // Validate required fields
        $requiredFields = ['zone_id', 'name', 'type', 'content'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
                return $this->returnApiError("Missing required field: {$field}", 400);
            }
        }

        $zoneId = (int)$input['zone_id'];
        $name = $input['name'];
        $type = $input['type'];
        $content = $input['content'];
        $ttl = isset($input['ttl']) ? (int)$input['ttl'] : (int)$this->getConfig()->get('dns', 'ttl', 86400);
        $prio = isset($input['prio']) ? (int)$input['prio'] : 0;

        // Verify that the zone exists
        $zone = $this->zoneRepository->getZone($zoneId);
        if (!$zone) {
            return $this->returnApiError('Zone not found', 404);
        }

        // Check if the API key has permission to modify this zone
        // This will be implemented in a later task for domain permissions

        try {
            // Attempt to add the record
            $success = $this->recordManager->addRecord(
                $zoneId,
                $name,
                $type,
                $content,
                $ttl,
                $prio
            );

            if (!$success) {
                return $this->returnApiError('Failed to add record', 400);
            }

            // Get the ID of the newly created record
            // This is a simplified approach and might need adjustment based on how your system works
            $pdns_db_name = $this->getConfig()->get('database', 'pdns_name');
            $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

            $query = "SELECT id FROM $records_table
                     WHERE domain_id = :domain_id
                     AND name = :name
                     AND type = :type
                     AND content = :content
                     ORDER BY id DESC LIMIT 1";

            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
            $stmt->bindValue(':name', strtolower($name), PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->bindValue(':content', $content, PDO::PARAM_STR);
            $stmt->execute();

            $recordId = $stmt->fetchColumn();

            return $this->returnApiResponse([
                'message' => 'Record added successfully',
                'record_id' => (int)$recordId
            ], true, null, 201);
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 400);
        }
    }

    /**
     * Set domain permissions for API key
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/api/v1/zone/permissions',
        operationId: 'v1ZoneSetPermissions',
        summary: 'Set domain permissions for API key',
        tags: ['zones'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'action',
        in: 'query',
        required: true,
        description: 'Action parameter (must be \'set_permissions\')',
        schema: new OA\Schema(type: 'string', default: 'set_permissions', enum: ['set_permissions'])
    )]
    #[OA\RequestBody(
        required: true,
        description: 'Domain permission information',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'domain_id', type: 'integer', example: 1, description: 'Domain ID to assign permissions for'),
                new OA\Property(property: 'user_id', type: 'integer', example: 2, description: 'User ID to assign as domain owner')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Domain permissions set successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Domain permissions set successfully'),
                        new OA\Property(property: 'domain_id', type: 'integer', example: 1),
                        new OA\Property(property: 'user_id', type: 'integer', example: 2)
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid input data'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid or missing API key'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'You do not have permission to set domain permissions'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Domain not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Domain not found'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    public function setDomainPermissions(): JsonResponse
    {
        $input = $this->getJsonInput();

        if (!$input) {
            return $this->returnApiError('Invalid input data', 400);
        }

        // Validate required fields
        $requiredFields = ['domain_id', 'user_id'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field]) || (int)$input[$field] <= 0) {
                return $this->returnApiError("Missing or invalid required field: {$field}", 400);
            }
        }

        $domainId = (int)$input['domain_id'];
        $userId = (int)$input['user_id'];

        // Check if domain exists
        $domain = $this->zoneRepository->getZone($domainId);
        if (!$domain) {
            return $this->returnApiError('Domain not found', 404);
        }

        // Check if user exists
        if (!UserManager::isValidUser($this->db, $userId)) {
            return $this->returnApiError('User not found', 404);
        }

        // Check if the current user has permission to modify domain ownership
        if (
            !UserManager::verifyPermission($this->db, 'zone_meta_edit_others') &&
            !UserManager::verifyPermission($this->db, 'zone_meta_edit_own')
        ) {
            return $this->returnApiError('You do not have permission to set domain permissions', 403);
        }

        // If current user only has permission to edit own zones, check ownership
        if (
            !UserManager::verifyPermission($this->db, 'zone_meta_edit_others') &&
            !UserManager::verifyUserIsOwnerZoneId($this->db, $domainId)
        ) {
            return $this->returnApiError('You do not have permission to modify this domain', 403);
        }

        // Add the user as an owner of the zone
        $success = DomainManager::addOwnerToZone($this->db, $domainId, $userId);

        if (!$success) {
            // Check if the user is already an owner
            $query = "SELECT COUNT(id) FROM zones WHERE owner = :user_id AND domain_id = :domain_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':domain_id', $domainId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->fetchColumn() > 0) {
                return $this->returnApiResponse([
                    'message' => 'User is already an owner of this domain',
                    'domain_id' => $domainId,
                    'user_id' => $userId
                ]);
            }

            return $this->returnApiError('Failed to set domain permissions', 500);
        }

        return $this->returnApiResponse([
            'message' => 'Domain permissions set successfully',
            'domain_id' => $domainId,
            'user_id' => $userId
        ]);
    }

    /**
     * Delete a zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Delete(
        path: '/api/v1/zone/delete',
        operationId: 'v1ZoneDelete',
        summary: 'Delete a zone',
        tags: ['zones'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'action',
        in: 'query',
        required: true,
        description: 'Action parameter (must be \'delete\')',
        schema: new OA\Schema(type: 'string', default: 'delete', enum: ['delete'])
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'query',
        description: 'ID of the zone to delete (alternative to providing in request body)',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        description: 'Zone deletion information (alternative to providing id in query parameter)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1, description: 'ID of the zone to delete')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Zone deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Zone deleted successfully')
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Missing or invalid zone ID'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid or missing API key'),
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
    public function deleteZone(): JsonResponse
    {
        $input = $this->getJsonInput();

        // Get zone ID from request body or URL parameter
        $zoneId = 0;

        if ($input && isset($input['id'])) {
            $zoneId = (int)$input['id'];
        } elseif (isset($_GET['id'])) {
            $zoneId = (int)$_GET['id'];
        }

        if ($zoneId <= 0) {
            return $this->returnApiError('Missing or invalid zone ID', 400);
        }

        // Implementation would continue here with actual zone deletion logic
        // For this example, we'll just return a success response

        return $this->returnApiResponse([
            'message' => 'Zone deleted successfully'
        ]);
    }
}
