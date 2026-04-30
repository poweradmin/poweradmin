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
 * RESTful API controller for zone records operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V1;

use Exception;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZonesRecordsController extends PublicApiController
{
    private ZoneRepositoryInterface $zoneRepository;
    private RecordRepositoryInterface $recordRepository;
    private RecordManagerInterface $recordManager;
    private SOARecordManager $soaRecordManager;
    private ApiPermissionService $permissionService;
    private RecordCommentService $recordCommentService;
    private DnsBackendProvider $backendProvider;
    private RecordChangeLogger $changeLogger;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->backendProvider = DnsBackendProviderFactory::create($this->db, $this->getConfig(), $this->logger);
        $repositoryFactory = $this->getRepositoryFactory($this->backendProvider);
        $this->zoneRepository = $this->createZoneRepository();
        $this->recordRepository = $repositoryFactory->createRecordRepository();
        $this->permissionService = new ApiPermissionService($this->db);

        $recordCommentRepository = $repositoryFactory->createRecordCommentRepository();
        $this->recordCommentService = new RecordCommentService($recordCommentRepository);

        // Initialize services using factory
        $validationService = DnsServiceFactory::createDnsRecordValidationService($this->db, $this->getConfig(), $this->backendProvider);
        $this->soaRecordManager = new SOARecordManager($this->db, $this->getConfig(), $this->backendProvider);
        $domainRepository = $repositoryFactory->createDomainRepository();
        $this->recordManager = new RecordManager(
            $this->db,
            $this->getConfig(),
            $validationService,
            $this->soaRecordManager,
            $domainRepository,
            $this->backendProvider
        );
        $this->changeLogger = new RecordChangeLogger($this->db);
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
        path: '/v1/zones/{id}/records',
        operationId: 'v1ListZoneRecords',
        deprecated: true,
        summary: 'List all records in a zone. Use /v2/ endpoints instead.',
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
                            new OA\Property(property: 'disabled', type: 'integer', example: 0, description: 'Disabled flag (0 = enabled, 1 = disabled)'),
                            new OA\Property(property: 'auth', type: 'integer', example: 1, description: 'Authoritative flag (1 = authoritative, 0 = non-authoritative/glue record)')
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

            // Format record data
            $formattedRecords = array_map(function ($record) {
                return [
                    'id' => RecordIdHelper::normalizeId($record['id']),
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'content' => $record['content'],
                    'ttl' => (int)$record['ttl'],
                    'priority' => isset($record['prio']) ? (int)$record['prio'] : null,
                    'disabled' => isset($record['disabled']) ? (bool)DbCompat::boolFromDb($record['disabled']) : false,
                    'auth' => isset($record['auth']) ? (bool)DbCompat::boolFromDb($record['auth']) : true
                ];
            }, $records);

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
        path: '/v1/zones/{id}/records/{recordId}',
        operationId: 'v1GetZoneRecord',
        deprecated: true,
        summary: 'Get a specific record. Use /v2/ endpoints instead.',
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
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'name', type: 'string', example: 'www.example.com'),
                        new OA\Property(property: 'type', type: 'string', example: 'A'),
                        new OA\Property(property: 'content', type: 'string', example: '192.168.1.1'),
                        new OA\Property(property: 'ttl', type: 'integer', example: 3600),
                        new OA\Property(property: 'priority', type: 'integer', example: 10),
                        new OA\Property(property: 'disabled', type: 'integer', example: 0, description: 'Disabled flag (0 = enabled, 1 = disabled)'),
                        new OA\Property(property: 'auth', type: 'integer', example: 1, description: 'Authoritative flag (1 = authoritative, 0 = non-authoritative/glue record). Automatically managed by PowerDNS.')
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
            $zoneId = $this->pathParameters['id'];
            $recordId = $this->pathParameters['record_id'];

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

            $formattedRecord = [
                'id' => (int)$record['id'],
                'name' => $record['name'],
                'type' => $record['type'],
                'content' => $record['content'],
                'ttl' => (int)$record['ttl'],
                'priority' => isset($record['prio']) ? (int)$record['prio'] : null,
                'disabled' => isset($record['disabled']) ? (bool)DbCompat::boolFromDb($record['disabled']) : false,
                'auth' => isset($record['auth']) ? (bool)DbCompat::boolFromDb($record['auth']) : true
            ];

            return $this->returnApiResponse($formattedRecord, true, 'Record retrieved successfully', 200);
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
        path: '/v1/zones/{id}/records',
        operationId: 'v1CreateZoneRecord',
        deprecated: true,
        summary: 'Create a new record in a zone. Use /v2/ endpoints instead.',
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
                new OA\Property(property: 'disabled', type: 'integer', example: 0, description: 'Disabled flag (0 = enabled, 1 = disabled). Default: 0')
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
                        new OA\Property(property: 'disabled', type: 'integer', example: 0, description: 'Disabled flag (0 = enabled, 1 = disabled)')
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
                $value = $this->inputString($input, $field);
                if ($value === null || trim($value) === '') {
                    return $this->returnApiError("Field '$field' is required", 400);
                }
            }

            // Extract and validate input
            $name = trim($this->inputString($input, 'name', ''));
            $type = strtoupper(trim($this->inputString($input, 'type', '')));
            $content = trim($this->inputString($input, 'content', ''));
            $ttl = $this->inputInt($input, 'ttl', 3600);
            $priority = $this->inputInt($input, 'priority', 0);
            $disabled = $this->inputIntFromBool($input, 'disabled', 0);

            if ($ttl === null || $priority === null || $disabled === null) {
                return $this->returnApiError('Fields ttl, priority, and disabled must be numeric', 400);
            }

            // Validate TTL
            if ($ttl < 1) {
                return $this->returnApiError('TTL must be greater than 0', 400);
            }

            // Validate disabled field
            if ($disabled !== 0 && $disabled !== 1) {
                return $this->returnApiError('Disabled field must be 0 or 1', 400);
            }

            // Validate the record using the validation service
            $validationService = DnsServiceFactory::createDnsRecordValidationService($this->db, $this->getConfig(), $this->backendProvider);
            $repositoryFactory = $this->getRepositoryFactory($this->backendProvider);
            $domainRepository = $repositoryFactory->createDomainRepository();

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

            // Insert record via backend provider
            // Wrap insert + SOA update in a transaction for atomicity (SQL backend only).
            // API backend polls the DB for the new record ID after the HTTP call;
            // opening a transaction before that would hide the row due to MVCC snapshot isolation.
            $useTransaction = !$this->backendProvider->isApiBackend();
            if ($useTransaction) {
                $this->db->beginTransaction();
            }

            $newRecordId = $this->insertRecordViaBackend($zoneId, $normalizedName, $type, $content, $ttl, $priority, $disabled);

            if ($newRecordId === null) {
                if ($useTransaction) {
                    $this->db->rollBack();
                }
                return $this->returnApiError('Failed to create record', 500);
            }

            // Update SOA serial for the zone
            if ($type !== 'SOA') {
                $this->updateSOASerial($zoneId);
            }

            if ($useTransaction) {
                $this->db->commit();
            }

            try {
                $zoneName = $this->backendProvider->getZoneNameById($zoneId);
                $this->changeLogger->logRecordCreate([
                    'id' => $newRecordId,
                    'name' => $normalizedName,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => $ttl,
                    'prio' => $priority,
                    'disabled' => (bool) $disabled,
                    'zone_name' => is_string($zoneName) ? $zoneName : null,
                ], $zoneId);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to write record create log: {error}', ['error' => $e->getMessage()]);
            }

            $responseData = [
                'record_id' => $newRecordId,
                'name' => $name,
                'type' => $type,
                'content' => $content,
                'ttl' => $ttl,
                'priority' => $priority,
                'disabled' => $disabled
            ];

            return $this->returnApiResponse($responseData, true, 'Record created successfully', 201);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->returnApiError('Failed to create record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an existing record
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Put(
        path: '/v1/zones/{id}/records/{recordId}',
        operationId: 'v1UpdateZoneRecord',
        deprecated: true,
        summary: 'Update an existing record. Use /v2/ endpoints instead.',
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
                new OA\Property(property: 'disabled', type: 'integer', example: 0, description: 'Disabled flag (0 = enabled, 1 = disabled)')
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
    private function updateRecord(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            $recordId = RecordIdHelper::normalizeId($this->pathParameters['record_id'] ?? '');

            if ($zoneId <= 0 || empty($recordId)) {
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
            $name = $this->inputString($input, 'name', $existingRecord['name']);
            $type = $this->inputString($input, 'type', $existingRecord['type']);
            $content = $this->inputString($input, 'content', $existingRecord['content']);
            $ttl = $this->inputInt($input, 'ttl', (int)$existingRecord['ttl']);
            $prio = $this->inputInt($input, 'priority', (int)($existingRecord['prio'] ?? 0));
            $disabled = $this->inputIntFromBool($input, 'disabled', DbCompat::boolFromDb($existingRecord['disabled'] ?? 0));
            if ($name === null || $type === null || $content === null || $ttl === null || $prio === null || $disabled === null) {
                return $this->returnApiError('Invalid field types in request body', 400);
            }
            $recordData = [
                'rid' => $recordId,
                'zid' => $zoneId,
                'name' => $name,
                'type' => strtoupper($type),
                'content' => $content,
                'ttl' => $ttl,
                'prio' => $prio,
                'disabled' => $disabled
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

            return $this->returnApiResponse(null, true, 'Record updated successfully', 200);
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
        path: '/v1/zones/{id}/records/{recordId}',
        operationId: 'v1DeleteZoneRecord',
        deprecated: true,
        summary: 'Delete a record. Use /v2/ endpoints instead.',
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
            $recordId = RecordIdHelper::normalizeId($this->pathParameters['record_id'] ?? '');

            if ($zoneId <= 0 || empty($recordId)) {
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

            // Delete comment for this specific record (per-record comment by record_id)
            $this->recordCommentService->deleteCommentByRecordId($recordId);

            // For backward compatibility, also clean up RRset-based comments if no similar records remain
            if (!$this->recordRepository->hasSimilarRecords($zoneId, $existingRecord['name'], $existingRecord['type'], $recordId)) {
                $this->recordCommentService->deleteComment($zoneId, $existingRecord['name'], $existingRecord['type']);
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
     * Insert a validated record via the DNS backend provider
     *
     * @param int $zoneId Zone ID
     * @param string $name Record name (already normalized)
     * @param string $type Record type
     * @param string $content Record content (already validated)
     * @param int $ttl TTL value
     * @param int $priority Priority value
     * @param int $disabled Disabled flag (0 = enabled, 1 = disabled)
     * @return int|string|null Record ID if successful, null on failure
     */
    private function insertRecordViaBackend(int $zoneId, string $name, string $type, string $content, int $ttl, int $priority, int $disabled = 0): int|string|null
    {
        try {
            return $this->backendProvider->createRecordAtomic($zoneId, $name, $type, $content, $ttl, $priority, $disabled);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to insert record: {message}', ['message' => $e->getMessage()]);
            return null;
        }
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
}
