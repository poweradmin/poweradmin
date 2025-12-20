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
 * RESTful API controller for zone records operations
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
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZonesRecordsController extends PublicApiController
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
     * Handle zone records requests
     */
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['record_id']) ? $this->getRecord() : $this->listRecords(),
            'POST' => $this->createRecord(),
            'PUT' => $this->updateRecord(),
            'DELETE' => $this->deleteRecord(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * List all records in a zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/zones/{id}/records',
        operationId: 'v2ListZoneRecords',
        summary: 'List all records in a zone',
        tags: ['records'],
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
        description: 'Filter by record type',
        schema: new OA\Schema(type: 'string', example: 'A')
    )]
    #[OA\Response(
        response: 200,
        description: 'Records retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Records retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'name', type: 'string', example: 'www.example.com'),
                            new OA\Property(property: 'type', type: 'string', example: 'A'),
                            new OA\Property(property: 'content', type: 'string', example: '192.168.1.1'),
                            new OA\Property(property: 'ttl', type: 'integer', example: 3600),
                            new OA\Property(property: 'priority', type: 'integer', example: 10),
                            new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag (false = enabled, true = disabled)'),
                            new OA\Property(property: 'auth', type: 'boolean', example: true, description: 'Authoritative flag (true = authoritative, false = non-authoritative/glue record)')
                        ],
                        type: 'object'
                    )
                )
            ]
        )
    )]
    private function listRecords(): JsonResponse
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

            // Filter out ghost records (records with NULL type or content)
            // These are invalid database entries that should not be exposed via API
            $validRecords = array_filter($records, function ($record) {
                return !empty($record['type']) && !empty($record['name']);
            });

            // Format record data
            $formattedRecords = array_map(function ($record) {
                return [
                    'id' => (int)$record['id'],
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'content' => $this->stripTxtQuotes($record['content'], $record['type']),
                    'ttl' => (int)$record['ttl'],
                    'priority' => isset($record['prio']) ? (int)$record['prio'] : null,
                    'disabled' => isset($record['disabled']) ? DbCompat::boolFromDb($record['disabled']) : 0,
                    'auth' => isset($record['auth']) ? DbCompat::boolFromDb($record['auth']) : 1
                ];
            }, $validRecords);

            return $this->returnApiResponse($formattedRecords, true, 'Records retrieved successfully', 200);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to retrieve records: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a specific record
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/zones/{id}/records/{recordId}',
        operationId: 'v2GetZoneRecord',
        summary: 'Get a specific record',
        tags: ['records'],
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
        name: 'recordId',
        in: 'path',
        description: 'Record ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Record retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Record retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(
                            property: 'record',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'www.example.com'),
                                new OA\Property(property: 'type', type: 'string', example: 'A'),
                                new OA\Property(property: 'content', type: 'string', example: '192.168.1.1'),
                                new OA\Property(property: 'ttl', type: 'integer', example: 3600),
                                new OA\Property(property: 'priority', type: 'integer', example: 10),
                                new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag (false = enabled, true = disabled)'),
                                new OA\Property(property: 'auth', type: 'boolean', example: true, description: 'Authoritative flag (true = authoritative, false = non-authoritative/glue record). Automatically managed by PowerDNS.')
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
        description: 'Record not found'
    )]
    private function getRecord(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $zoneId = (int)$this->pathParameters['id'];
            $recordId = (int)$this->pathParameters['record_id'];

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to view this zone
            if (!$this->permissionService->canViewZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to view this zone', 403);
            }

            // Get specific record
            $record = $this->recordRepository->getRecordById($recordId);
            if (!$record || $record['domain_id'] != $zoneId) {
                return $this->returnApiError('Record not found in this zone', 404);
            }

            $domainRepository = new DomainRepository($this->db, $this->getConfig());
            $zoneName = $domainRepository->getDomainNameById($zoneId);

            $formattedRecord = [
                'id' => (int)$record['id'],
                'zone_id' => $zoneId,
                'name' => DnsHelper::stripZoneSuffix($record['name'], $zoneName),
                'type' => $record['type'],
                'content' => $this->stripTxtQuotes($record['content'], $record['type']),
                'ttl' => (int)$record['ttl'],
                'priority' => isset($record['prio']) ? (int)$record['prio'] : 0,
                'disabled' => isset($record['disabled']) ? (bool)DbCompat::boolFromDb($record['disabled']) : false,
                'auth' => isset($record['auth']) ? (bool)DbCompat::boolFromDb($record['auth']) : true
            ];

            return $this->returnApiResponse(['record' => $formattedRecord], true, 'Record retrieved successfully', 200);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to retrieve record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new record in a zone
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/v2/zones/{id}/records',
        operationId: 'v2CreateZoneRecord',
        summary: 'Create a new record in a zone',
        tags: ['records'],
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
        description: 'Record data',
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'www.example.com', description: 'Record name (FQDN)'),
                new OA\Property(property: 'type', type: 'string', example: 'A', description: 'Record type (A, AAAA, CNAME, MX, etc.)'),
                new OA\Property(property: 'content', type: 'string', example: '192.168.1.1', description: 'Record content/value'),
                new OA\Property(property: 'ttl', type: 'integer', example: 3600, description: 'Time to live (TTL) in seconds'),
                new OA\Property(property: 'priority', type: 'integer', example: 10, description: 'Priority (for MX, SRV records, etc.)'),
                new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag (false = enabled, true = disabled). Default: false'),
                new OA\Property(property: 'create_ptr', type: 'boolean', example: false, description: 'Automatically create PTR record (reverse DNS). Only applicable for A and AAAA records. Requires matching reverse zone. Default: false')
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Record created successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Record created successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'record_id', type: 'integer', example: 456),
                        new OA\Property(property: 'name', type: 'string', example: 'www.example.com'),
                        new OA\Property(property: 'type', type: 'string', example: 'A'),
                        new OA\Property(property: 'content', type: 'string', example: '192.168.1.1'),
                        new OA\Property(property: 'ttl', type: 'integer', example: 3600),
                        new OA\Property(property: 'priority', type: 'integer', example: 10),
                        new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag (false = enabled, true = disabled)')
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
                new OA\Property(property: 'message', type: 'string', example: 'Invalid record type'),
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
    private function createRecord(): JsonResponse
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
            $requiredFields = ['name', 'type', 'content'];
            foreach ($requiredFields as $field) {
                if (!isset($input[$field]) || trim($input[$field]) === '') {
                    return $this->returnApiError("Field '$field' is required", 400);
                }
            }

            // Extract and validate input
            $name = trim($input['name']);
            $type = strtoupper(trim($input['type']));
            $originalContent = trim($input['content']);
            $content = $originalContent;
            $ttl = isset($input['ttl']) ? (int)$input['ttl'] : 3600;
            $priority = isset($input['priority']) ? (int)$input['priority'] : 0;
            $disabled = isset($input['disabled']) ? (int)$input['disabled'] : 0;
            $createPtr = isset($input['create_ptr']) ? (bool)$input['create_ptr'] : false;

            // Validate TTL
            if ($ttl < 1) {
                return $this->returnApiError('TTL must be greater than 0', 400);
            }

            // Validate disabled field
            if ($disabled !== 0 && $disabled !== 1) {
                return $this->returnApiError('Disabled field must be 0 or 1', 400);
            }

            // Validate the record using the validation service
            $validationService = DnsServiceFactory::createDnsRecordValidationService($this->db, $this->getConfig());
            $domainRepository = new DomainRepository($this->db, $this->getConfig());

            // Get zone name for validation
            $zoneName = $domainRepository->getDomainNameById($zoneId);
            if ($zoneName === null) {
                return $this->returnApiError(_('Zone not found.'), 404);
            }

            // Normalize record name to full FQDN (always, regardless of display setting)
            // This converts @ to zone apex and ensures proper zone suffix
            $name = DnsHelper::restoreZoneSuffix($name, $zoneName);

            // Normalize the hostname
            $hostnameValidator = new HostnameValidator($this->getConfig());
            $normalizedName = $hostnameValidator->normalizeRecordName($name, $zoneName);

            // Format content using DnsFormatter, with V2 API always auto-quoting TXT records
            $dnsFormatter = new DnsFormatter($this->getConfig());
            $content = $dnsFormatter->formatContent($type, $content);

            // V2 API always auto-quotes TXT records, even when txt_auto_quote config is false
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
                $errorMessage = $validationResult->getFirstError();
                return $this->returnApiError($errorMessage, 400);
            }

            // Get validated and normalized values from validation result
            $validatedData = $validationResult->getData();
            $validatedContent = $validatedData['content'] ?? $content;
            $validatedTtl = $validatedData['ttl'] ?? $ttl;
            $validatedPriority = $validatedData['prio'] ?? $priority;

            // If validation passes, insert the record with validated values
            $success = $this->insertRecordDirect($zoneId, $normalizedName, $type, $validatedContent, $validatedTtl, $validatedPriority, $disabled);

            if (!$success) {
                return $this->returnApiError('Failed to create record', 500);
            }

            // Get the newly created record ID (we'll need to query for it since addRecord doesn't return ID)
            $records = $this->recordRepository->getRecordsByDomainId($zoneId);
            $newRecord = null;
            foreach ($records as $record) {
                if ($record['name'] === $normalizedName && $record['type'] === $type && $record['content'] === $validatedContent) {
                    $newRecord = $record;
                    break;
                }
            }

            // Create PTR record if requested and record type is A or AAAA
            $ptrCreated = false;
            $ptrMessage = '';
            if ($createPtr && ($type === 'A' || $type === 'AAAA')) {
                try {
                    $dnsRecord = new DnsRecord($this->db, $this->getConfig());
                    $logger = new LegacyLogger($this->db);
                    $reverseRecordCreator = new ReverseRecordCreator($this->db, $this->getConfig(), $logger, $dnsRecord);

                    $ptrResult = $reverseRecordCreator->createReverseRecord(
                        $name,
                        $type,
                        $validatedContent,
                        $zoneId,
                        $validatedTtl,
                        $validatedPriority
                    );

                    if ($ptrResult['success']) {
                        $ptrCreated = true;
                        $ptrMessage = ' PTR record created successfully.';
                    } else {
                        // PTR creation failed but don't fail the entire request
                        $ptrMessage = ' PTR record creation failed: ' . $ptrResult['message'];
                    }
                } catch (Exception $e) {
                    // Log error but don't fail the entire request
                    $ptrMessage = ' PTR record creation failed: ' . $e->getMessage();
                    error_log('PTR record creation failed: ' . $e->getMessage());
                }
            }

            $responseData = [
                'id' => $newRecord ? (int)$newRecord['id'] : null,
                'zone_id' => $zoneId,
                'name' => DnsHelper::stripZoneSuffix($name, $zoneName),
                'type' => $type,
                'content' => $originalContent,
                'ttl' => $validatedTtl,
                'priority' => $validatedPriority,
                'disabled' => (bool)$disabled,
                'auth' => true,
                'ptr_created' => $ptrCreated
            ];

            $message = 'Record created successfully' . $ptrMessage;
            return $this->returnApiResponse(['record' => $responseData], true, $message, 201);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to create record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an existing record
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Put(
        path: '/v2/zones/{id}/records/{recordId}',
        operationId: 'v2UpdateZoneRecord',
        summary: 'Update an existing record',
        tags: ['records'],
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
        name: 'recordId',
        in: 'path',
        description: 'Record ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        description: 'Record update data',
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'www.example.com'),
                new OA\Property(property: 'type', type: 'string', example: 'A'),
                new OA\Property(property: 'content', type: 'string', example: '192.168.1.1'),
                new OA\Property(property: 'ttl', type: 'integer', example: 3600),
                new OA\Property(property: 'priority', type: 'integer', example: 10),
                new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag (false = enabled, true = disabled)')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Record updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Record updated successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(
                            property: 'record',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'www.example.com'),
                                new OA\Property(property: 'type', type: 'string', example: 'A'),
                                new OA\Property(property: 'content', type: 'string', example: '192.168.1.1'),
                                new OA\Property(property: 'ttl', type: 'integer', example: 3600),
                                new OA\Property(property: 'priority', type: 'integer', example: 10),
                                new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag (false = enabled, true = disabled)'),
                                new OA\Property(property: 'auth', type: 'boolean', example: true, description: 'Authoritative flag (true = authoritative, false = non-authoritative/glue record)')
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
        description: 'Record not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Record not found'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    private function updateRecord(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            $recordId = (int)($this->pathParameters['record_id'] ?? 0);

            if ($zoneId <= 0 || $recordId <= 0) {
                return $this->returnApiError('Valid zone ID and record ID are required', 400);
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

            // Get existing record
            $existingRecord = $this->recordRepository->getRecordById($recordId);
            if (!$existingRecord || $existingRecord['domain_id'] != $zoneId) {
                return $this->returnApiError('Record not found in this zone', 404);
            }

            $input = json_decode($this->request->getContent(), true);
            if (!$input) {
                return $this->returnApiError('Invalid JSON in request body', 400);
            }

            // Prepare record data for update - use existing values if not provided
            $recordData = [
                'rid' => $recordId,
                'zid' => $zoneId,
                'name' => $input['name'] ?? $existingRecord['name'],
                'type' => isset($input['type']) ? strtoupper(trim($input['type'])) : $existingRecord['type'],
                'content' => $input['content'] ?? $existingRecord['content'],
                'ttl' => isset($input['ttl']) ? (int)$input['ttl'] : (int)$existingRecord['ttl'],
                'prio' => isset($input['priority']) ? (int)$input['priority'] : (int)($existingRecord['prio'] ?? 0),
                'disabled' => isset($input['disabled']) ? (int)$input['disabled'] : DbCompat::boolFromDb($existingRecord['disabled'] ?? 0)
            ];

            // Validate TTL
            if ($recordData['ttl'] < 1) {
                return $this->returnApiError('TTL must be greater than 0', 400);
            }

            // Validate disabled field
            if ($recordData['disabled'] !== 0 && $recordData['disabled'] !== 1) {
                return $this->returnApiError('Disabled field must be 0 or 1', 400);
            }

            // Use RecordManager to edit the record
            $success = $this->recordManager->editRecord($recordData);

            if (!$success) {
                return $this->returnApiError('Failed to update record', 500);
            }

            // Update SOA serial after editing the record (except for SOA records themselves)
            if ($recordData['type'] !== 'SOA') {
                $this->updateSOASerial($zoneId);
            }

            // Get the updated record to return
            $updatedRecord = $this->recordRepository->getRecordById($recordId);

            // Get zone name for stripping suffix
            $domainRepository = new DomainRepository($this->db, $this->getConfig());
            $zoneName = $domainRepository->getDomainNameById($zoneId);

            $formattedRecord = [
                'id' => (int)$updatedRecord['id'],
                'zone_id' => $zoneId,
                'name' => DnsHelper::stripZoneSuffix($updatedRecord['name'], $zoneName),
                'type' => $updatedRecord['type'],
                'content' => $this->stripTxtQuotes($updatedRecord['content'], $updatedRecord['type']),
                'ttl' => (int)$updatedRecord['ttl'],
                'priority' => isset($updatedRecord['prio']) ? (int)$updatedRecord['prio'] : 0,
                'disabled' => isset($updatedRecord['disabled']) ? (bool)DbCompat::boolFromDb($updatedRecord['disabled']) : false,
                'auth' => isset($updatedRecord['auth']) ? (bool)DbCompat::boolFromDb($updatedRecord['auth']) : true
            ];

            return $this->returnApiResponse(['record' => $formattedRecord], true, 'Record updated successfully', 200);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to update record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a record
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Delete(
        path: '/v2/zones/{id}/records/{recordId}',
        operationId: 'v2DeleteZoneRecord',
        summary: 'Delete a record',
        tags: ['records'],
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
        name: 'recordId',
        in: 'path',
        description: 'Record ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 204,
        description: 'Record deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Record deleted successfully'),
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
    private function deleteRecord(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            $recordId = (int)($this->pathParameters['record_id'] ?? 0);

            if ($zoneId <= 0 || $recordId <= 0) {
                return $this->returnApiError('Valid zone ID and record ID are required', 400);
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

            // Verify record exists in this zone
            $existingRecord = $this->recordRepository->getRecordById($recordId);
            if (!$existingRecord || $existingRecord['domain_id'] != $zoneId) {
                return $this->returnApiError('Record not found in this zone', 404);
            }

            // Get record type before deletion (for SOA serial update logic)
            $recordType = $existingRecord['type'];

            // Use RecordManager to delete the record
            $success = $this->recordManager->deleteRecord($recordId);

            if (!$success) {
                return $this->returnApiError('Failed to delete record', 500);
            }

            // Update SOA serial after deleting the record (except for SOA records themselves)
            if ($recordType !== 'SOA') {
                $this->updateSOASerial($zoneId);
            }

            return $this->returnApiResponse(null, true, 'Record deleted successfully', 204);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to delete record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Insert a validated record directly into the database (API-specific method)
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
        $maxRetries = 3;
        $retryDelay = 50000; // 50ms in microseconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

                // Start transaction
                $this->db->beginTransaction();

                // Insert the record
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

                $result = $stmt->execute();

                if (!$result) {
                    $this->db->rollBack();
                    return false;
                }

                // Update SOA serial if it's not a SOA record
                if ($type !== 'SOA') {
                    $this->updateSOASerial($zoneId);
                }

                $this->db->commit();
                return true;
            } catch (Exception $e) {
                $this->db->rollBack();

                // Check if this is a deadlock error (1213) or lock wait timeout (1205)
                $errorCode = $e->getCode();
                $isDeadlock = ($errorCode == 1213 || $errorCode == '40001' ||
                              strpos($e->getMessage(), '1213') !== false ||
                              strpos($e->getMessage(), 'Deadlock') !== false);

                if ($isDeadlock && $attempt < $maxRetries) {
                    // Log the retry attempt
                    error_log("Deadlock detected on attempt $attempt, retrying... " . $e->getMessage());
                    // Wait before retrying with exponential backoff
                    usleep($retryDelay * $attempt);
                    continue; // Retry the transaction
                }

                // Not a deadlock or max retries reached
                error_log('Failed to insert record: ' . $e->getMessage());
                return false;
            }
        }

        return false;
    }

    /**
     * Update SOA serial for a zone
     *
     * Delegates to SOARecordManager which handles all edge cases:
     * - 100+ changes per day (increments date instead of breaking)
     * - Non-date based serials (simple increment)
     * - Future-dated serials (preserved)
     * - Overflow protection at 1979999999
     *
     * @param int $zoneId Zone ID
     * @return void
     */
    private function updateSOASerial(int $zoneId): void
    {
        $this->soaRecordManager->updateSOASerial($zoneId);
    }

    /**
     * Strip quotes from single-string TXT records for V2 API responses
     *
     * V2 API automatically quotes TXT records on creation, but strips quotes when reading
     * to provide a clean API experience. Multi-string TXT records (e.g., "part1" "part2")
     * are preserved as-is since they represent long values split across multiple strings.
     *
     * @param string $content The TXT record content from database
     * @param string $type The record type
     * @return string The formatted content (quotes stripped for single-string TXT records)
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
