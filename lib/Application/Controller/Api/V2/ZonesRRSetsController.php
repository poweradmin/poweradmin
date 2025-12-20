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
 * RESTful API controller for zone RRSet (Resource Record Set) operations
 *
 * RRSets represent DNS-correct grouping of records: all records with the same
 * name and type are managed as a single set, matching PowerDNS behavior.
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Exception;
use PDO;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\DbCompat;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZonesRRSetsController extends PublicApiController
{
    private DbZoneRepository $zoneRepository;
    private RecordRepository $recordRepository;
    private RecordManagerInterface $recordManager;
    private SOARecordManager $soaRecordManager;
    private TableNameService $tableNameService;
    private ApiPermissionService $permissionService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $this->recordRepository = new RecordRepository($this->db, $this->getConfig());
        $this->tableNameService = new TableNameService($this->getConfig());
        $this->permissionService = new ApiPermissionService($this->db);

        // Initialize services using factory
        $validationService = DnsServiceFactory::createDnsRecordValidationService($this->db, $this->getConfig());
        $this->soaRecordManager = new SOARecordManager($this->db, $this->getConfig());
        $domainRepository = new DomainRepository($this->db, $this->getConfig());
        $this->recordManager = new RecordManager(
            $this->db,
            $this->getConfig(),
            $validationService,
            $this->soaRecordManager,
            $domainRepository
        );
    }

    /**
     * Handle zone RRSet requests
     */
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['name'], $this->pathParameters['type'])
                ? $this->getRRSet()
                : $this->listRRSets(),
            'POST', 'PUT', 'PATCH' => $this->replaceRRSet(),
            'DELETE' => $this->deleteRRSet(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * List all RRSets in a zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/zones/{id}/rrsets',
        operationId: 'v2ListZoneRRSets',
        summary: 'List all RRSets (Resource Record Sets) in a zone',
        description: 'Returns DNS records grouped by name and type. Each RRSet contains all records with the same name and type.',
        tags: ['rrsets'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        description: 'Zone ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Parameter(
        name: 'type',
        in: 'query',
        description: 'Filter by record type (e.g., A, AAAA, CNAME)',
        schema: new OA\Schema(type: 'string', example: 'A')
    )]
    #[OA\Response(
        response: 200,
        description: 'RRSets retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'RRSets retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'www.example.com', description: 'Fully qualified domain name'),
                            new OA\Property(property: 'type', type: 'string', example: 'A', description: 'Record type'),
                            new OA\Property(property: 'ttl', type: 'integer', example: 3600, description: 'Time to live in seconds'),
                            new OA\Property(
                                property: 'records',
                                type: 'array',
                                items: new OA\Items(
                                    properties: [
                                        new OA\Property(property: 'content', type: 'string', example: '192.168.1.1', description: 'Record content/value'),
                                        new OA\Property(property: 'priority', type: 'integer', example: 10, description: 'Priority (for MX, SRV records, etc.)'),
                                        new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag')
                                    ],
                                    type: 'object'
                                ),
                                description: 'Array of record data with same name and type'
                            )
                        ],
                        type: 'object'
                    ),
                    description: 'Array of RRSets in the zone'
                )
            ]
        )
    )]
    private function listRRSets(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $zoneId = $this->pathParameters['id'];
            $recordType = $this->request->query->get('type');

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to view this zone
            if (!$this->permissionService->canViewZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to view this zone', 403);
            }

            // Get records for the zone
            $records = $this->recordRepository->getRecordsByDomainId($zoneId, $recordType);

            // Group records into RRSets (by name + type)
            $rrsets = $this->groupIntoRRSets($records);

            return $this->returnApiResponse($rrsets, true, 'RRSets retrieved successfully', 200);
        } catch (\Throwable $e) {
            return $this->returnApiError('Failed to retrieve RRSets: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a specific RRSet by name and type
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/zones/{id}/rrsets/{name}/{type}',
        operationId: 'v2GetZoneRRSet',
        summary: 'Get a specific RRSet by name and type',
        description: 'Returns all records with the specified name and type as a single RRSet',
        tags: ['rrsets'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        description: 'Zone ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Parameter(
        name: 'name',
        in: 'path',
        description: 'Record name (use @ for zone apex)',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'www')
    )]
    #[OA\Parameter(
        name: 'type',
        in: 'path',
        description: 'Record type',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'A')
    )]
    #[OA\Response(
        response: 200,
        description: 'RRSet retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'RRSet retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'www', description: 'Record name without zone suffix'),
                        new OA\Property(property: 'type', type: 'string', example: 'A', description: 'Record type'),
                        new OA\Property(property: 'ttl', type: 'integer', example: 3600, description: 'Time to live in seconds'),
                        new OA\Property(
                            property: 'records',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'content', type: 'string', example: '192.168.1.1', description: 'Record content/value'),
                                    new OA\Property(property: 'priority', type: 'integer', example: 10, description: 'Priority (for MX, SRV records, etc.)'),
                                    new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag')
                                ],
                                type: 'object'
                            ),
                            description: 'Array of record data with same name and type'
                        )
                    ],
                    type: 'object',
                    description: 'RRSet object containing all records with the same name and type'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'RRSet not found'
    )]
    private function getRRSet(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $zoneId = (int)$this->pathParameters['id'];
            $name = $this->pathParameters['name'];
            $type = strtoupper($this->pathParameters['type']);

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to view this zone
            if (!$this->permissionService->canViewZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to view this zone', 403);
            }

            // Get zone name for FQDN construction
            $domainRepository = new DomainRepository($this->db, $this->getConfig());
            $zoneName = $domainRepository->getDomainNameById($zoneId);

            // Convert name to FQDN
            $fqdn = DnsHelper::restoreZoneSuffix($name, $zoneName);

            // Get all records matching this name and type
            $records = $this->recordRepository->getRRSetRecords($zoneId, $fqdn, $type);

            if (empty($records)) {
                return $this->returnApiError('RRSet not found', 404);
            }

            // Format as RRSet
            $rrset = $this->formatRRSet($records, $zoneName);

            return $this->returnApiResponse($rrset, true, 'RRSet retrieved successfully', 200);
        } catch (\Throwable $e) {
            return $this->returnApiError('Failed to retrieve RRSet: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Replace/Create an RRSet (PUT/POST/PATCH)
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Put(
        path: '/v2/zones/{id}/rrsets',
        operationId: 'v2ReplaceZoneRRSet',
        summary: 'Replace or create an RRSet',
        description: 'Replaces all records with the specified name and type. If the RRSet doesn\'t exist, it will be created.',
        tags: ['rrsets'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        description: 'Zone ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        description: 'RRSet data',
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'www', description: 'Record name (use @ for zone apex)'),
                new OA\Property(property: 'type', type: 'string', example: 'A', description: 'Record type'),
                new OA\Property(property: 'ttl', type: 'integer', example: 3600, description: 'Time to live in seconds'),
                new OA\Property(
                    property: 'records',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'content', type: 'string', example: '192.168.1.1', description: 'Record content/value'),
                            new OA\Property(property: 'priority', type: 'integer', example: 10, description: 'Priority (for MX, SRV records, etc.). Default: 0'),
                            new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag (default: false)')
                        ],
                        type: 'object'
                    ),
                    description: 'Array of record data'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'RRSet replaced successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'RRSet replaced successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'www'),
                        new OA\Property(property: 'type', type: 'string', example: 'A'),
                        new OA\Property(property: 'ttl', type: 'integer', example: 3600),
                        new OA\Property(property: 'records_created', type: 'integer', example: 2)
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    private function replaceRRSet(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $zoneId = (int)($this->pathParameters['id'] ?? 0);

            if ($zoneId <= 0) {
                return $this->returnApiError('Valid zone ID is required', 400);
            }

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to edit this zone
            if (!$this->permissionService->canEditZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to edit this zone', 403);
            }

            $input = json_decode($this->request->getContent(), true);
            if (!$input) {
                return $this->returnApiError('Invalid JSON in request body', 400);
            }

            // Validate required fields
            $requiredFields = ['name', 'type', 'records'];
            foreach ($requiredFields as $field) {
                if (!isset($input[$field])) {
                    return $this->returnApiError("Field '$field' is required", 400);
                }
            }

            if (!is_array($input['records']) || empty($input['records'])) {
                return $this->returnApiError("Field 'records' must be a non-empty array", 400);
            }

            $name = trim($input['name']);
            $type = strtoupper(trim($input['type']));
            $ttl = isset($input['ttl']) ? (int)$input['ttl'] : 3600;

            // Validate TTL
            if ($ttl < 1) {
                return $this->returnApiError('TTL must be greater than 0', 400);
            }

            // Get zone name
            $domainRepository = new DomainRepository($this->db, $this->getConfig());
            $zoneName = $domainRepository->getDomainNameById($zoneId);
            if ($zoneName === null) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Convert name to FQDN
            $fqdn = DnsHelper::restoreZoneSuffix($name, $zoneName);

            // Start transaction
            $this->db->beginTransaction();

            try {
                // Delete existing records with this name and type
                $existingRecords = $this->recordRepository->getRRSetRecords($zoneId, $fqdn, $type);
                foreach ($existingRecords as $record) {
                    $deleteResult = $this->recordManager->deleteRecord($record['id']);
                    if (!$deleteResult) {
                        $this->db->rollBack();
                        return $this->returnApiError('Failed to delete existing record with ID ' . $record['id'], 500);
                    }
                }

                // Create new records
                $recordsCreated = 0;
                $validationService = DnsServiceFactory::createDnsRecordValidationService($this->db, $this->getConfig());
                $hostnameValidator = new HostnameValidator($this->getConfig());
                $dnsFormatter = new DnsFormatter($this->getConfig());
                $normalizedName = $hostnameValidator->normalizeRecordName($fqdn, $zoneName);

                foreach ($input['records'] as $recordData) {
                    if (!isset($recordData['content'])) {
                        continue; // Skip invalid records
                    }

                    $content = trim($recordData['content']);
                    $disabled = isset($recordData['disabled']) ? (int)$recordData['disabled'] : 0;
                    $priority = isset($recordData['priority']) ? (int)$recordData['priority'] : 0;

                    // Format content
                    $content = $dnsFormatter->formatContent($type, $content);

                    // Auto-quote TXT records (V2 API convention)
                    if ($type === 'TXT') {
                        $content = trim($content);
                        if (!str_starts_with($content, '"') || !str_ends_with($content, '"')) {
                            $content = '"' . $content . '"';
                        }
                    }

                    // Validate the record
                    $dns_hostmaster = $this->getConfig()->get('dns', 'hostmaster');
                    $dns_ttl = $this->getConfig()->get('dns', 'ttl');

                    $validationResult = $validationService->validateRecord(
                        -1,
                        $zoneId,
                        $type,
                        $content,
                        $normalizedName,
                        $priority,
                        $ttl,
                        $dns_hostmaster,
                        (int)$dns_ttl
                    );

                    if (!$validationResult->isValid()) {
                        $this->db->rollBack();
                        return $this->returnApiError($validationResult->getFirstError(), 400);
                    }

                    // Get validated values
                    $validatedData = $validationResult->getData();
                    $validatedContent = $validatedData['content'] ?? $content;
                    $validatedTtl = $validatedData['ttl'] ?? $ttl;
                    $validatedPriority = $validatedData['prio'] ?? $priority;

                    // Insert record
                    $insertResult = $this->insertRecordDirect($zoneId, $normalizedName, $type, $validatedContent, $validatedTtl, $validatedPriority, $disabled);
                    if (!$insertResult) {
                        $this->db->rollBack();
                        return $this->returnApiError('Failed to insert record: ' . $content, 500);
                    }
                    $recordsCreated++;
                }

                if ($recordsCreated === 0) {
                    $this->db->rollBack();
                    return $this->returnApiError('No valid records to create', 400);
                }

                // Update SOA serial
                if ($type !== 'SOA') {
                    $this->soaRecordManager->updateSOASerial($zoneId);
                }

                $this->db->commit();

                $responseData = [
                    'name' => $name,
                    'type' => $type,
                    'ttl' => $ttl,
                    'records_created' => $recordsCreated
                ];

                return $this->returnApiResponse($responseData, true, 'RRSet replaced successfully', 200);
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            return $this->returnApiError('Failed to replace RRSet: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete an RRSet
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Delete(
        path: '/v2/zones/{id}/rrsets/{name}/{type}',
        operationId: 'v2DeleteZoneRRSet',
        summary: 'Delete an RRSet',
        description: 'Deletes all records with the specified name and type',
        tags: ['rrsets'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        description: 'Zone ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Parameter(
        name: 'name',
        in: 'path',
        description: 'Record name',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'www')
    )]
    #[OA\Parameter(
        name: 'type',
        in: 'path',
        description: 'Record type',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'A')
    )]
    #[OA\Response(
        response: 204,
        description: 'RRSet deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'RRSet deleted successfully'),
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'records_deleted', type: 'integer', example: 2)
                ], type: 'object')
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'RRSet not found'
    )]
    private function deleteRRSet(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            $name = $this->pathParameters['name'] ?? '';
            $type = strtoupper($this->pathParameters['type'] ?? '');

            if ($zoneId <= 0) {
                return $this->returnApiError('Valid zone ID is required', 400);
            }

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to edit this zone
            if (!$this->permissionService->canEditZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to edit this zone', 403);
            }

            // Get zone name
            $domainRepository = new DomainRepository($this->db, $this->getConfig());
            $zoneName = $domainRepository->getDomainNameById($zoneId);

            // Convert name to FQDN
            $fqdn = DnsHelper::restoreZoneSuffix($name, $zoneName);

            // Get all records matching this name and type
            $records = $this->recordRepository->getRRSetRecords($zoneId, $fqdn, $type);

            if (empty($records)) {
                return $this->returnApiError('RRSet not found', 404);
            }

            // Start transaction
            $this->db->beginTransaction();

            try {
                $recordsDeleted = 0;
                $totalRecords = count($records);

                foreach ($records as $record) {
                    $deleteResult = $this->recordManager->deleteRecord($record['id']);
                    if (!$deleteResult) {
                        $this->db->rollBack();
                        return $this->returnApiError(
                            'Failed to delete record with ID ' . $record['id'] . ' (name: ' . $record['name'] . ', type: ' . $type . ')',
                            500
                        );
                    }
                    $recordsDeleted++;
                }

                // Verify all records were deleted
                if ($recordsDeleted !== $totalRecords) {
                    $this->db->rollBack();
                    return $this->returnApiError(
                        'RRSet deletion incomplete: deleted ' . $recordsDeleted . ' of ' . $totalRecords . ' records',
                        500
                    );
                }

                // Update SOA serial
                if ($type !== 'SOA') {
                    $this->soaRecordManager->updateSOASerial($zoneId);
                }

                $this->db->commit();

                return $this->returnApiResponse(
                    ['records_deleted' => $recordsDeleted],
                    true,
                    'RRSet deleted successfully',
                    204
                );
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            return $this->returnApiError('Failed to delete RRSet: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Group individual records into RRSets (by name + type)
     *
     * @param array $records Individual records from database
     * @return array RRSets grouped by name and type
     */
    private function groupIntoRRSets(array $records): array
    {
        $rrsets = [];

        foreach ($records as $record) {
            $key = $record['name'] . '|' . $record['type'];

            if (!isset($rrsets[$key])) {
                $rrsets[$key] = [
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'ttl' => (int)$record['ttl'],
                    'records' => []
                ];
            }

            $rrsets[$key]['records'][] = [
                'content' => $this->stripTxtQuotes($record['content'], $record['type']),
                'priority' => isset($record['prio']) ? (int)$record['prio'] : 0,
                'disabled' => isset($record['disabled']) ? (bool)DbCompat::boolFromDb($record['disabled']) : false
            ];

            // Use the lowest TTL if records have different TTLs (shouldn't happen but be safe)
            if ((int)$record['ttl'] < $rrsets[$key]['ttl']) {
                $rrsets[$key]['ttl'] = (int)$record['ttl'];
            }
        }

        return array_values($rrsets);
    }

    /**
     * Format a group of records as a single RRSet
     *
     * @param array $records Records with same name and type
     * @param string $zoneName Zone name for stripping suffix
     * @return array Formatted RRSet
     */
    private function formatRRSet(array $records, string $zoneName): array
    {
        if (empty($records)) {
            return [];
        }

        $firstRecord = $records[0];

        return [
            'name' => DnsHelper::stripZoneSuffix($firstRecord['name'], $zoneName),
            'type' => $firstRecord['type'],
            'ttl' => (int)$firstRecord['ttl'],
            'records' => array_map(function ($record) {
                return [
                    'content' => $this->stripTxtQuotes($record['content'], $record['type']),
                    'priority' => isset($record['prio']) ? (int)$record['prio'] : 0,
                    'disabled' => isset($record['disabled']) ? (bool)DbCompat::boolFromDb($record['disabled']) : false
                ];
            }, $records)
        ];
    }

    /**
     * Insert a validated record directly into the database
     *
     * @param int $zoneId Zone ID
     * @param string $name Record name (already normalized)
     * @param string $type Record type
     * @param string $content Record content (already validated)
     * @param int $ttl TTL value
     * @param int $priority Priority value
     * @param int $disabled Disabled flag (0 = enabled, 1 = disabled)
     * @return bool True if successful
     */
    private function insertRecordDirect(int $zoneId, string $name, string $type, string $content, int $ttl, int $priority, int $disabled = 0): bool
    {
        try {
            $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

            // PowerDNS only searches for lowercase records - normalize before insert
            $name = strtolower($name);

            $query = "INSERT INTO $records_table (domain_id, name, type, content, ttl, prio, disabled)
                      VALUES (:zone_id, :name, :type, :content, :ttl, :prio, :disabled)";

            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->bindValue(':content', $content, PDO::PARAM_STR);
            $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
            $stmt->bindValue(':prio', $priority, PDO::PARAM_INT);
            $stmt->bindValue(':disabled', $disabled, PDO::PARAM_INT);

            return $stmt->execute();
        } catch (\Throwable $e) {
            error_log('Failed to insert record: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Strip quotes from single-string TXT records for V2 API responses
     *
     * @param string $content The TXT record content from database
     * @param string $type The record type
     * @return string The formatted content
     */
    private function stripTxtQuotes(string $content, string $type): string
    {
        if ($type !== 'TXT') {
            return $content;
        }

        $content = trim($content);
        $isMultiString = strpos($content, '" "') !== false;

        // Only strip quotes for single-string TXT records
        if (!$isMultiString && str_starts_with($content, '"') && str_ends_with($content, '"') && strlen($content) > 1) {
            return substr($content, 1, -1);
        }

        return $content;
    }
}
