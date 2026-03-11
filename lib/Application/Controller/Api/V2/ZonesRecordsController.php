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

namespace Poweradmin\Application\Controller\Api\V2;

use Exception;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZonesRecordsController extends PublicApiController
{
    private DbZoneRepository $zoneRepository;
    private RecordRepository $recordRepository;
    private RecordManagerInterface $recordManager;
    private SOARecordManager $soaRecordManager;
    private ApiPermissionService $permissionService;
    private RecordCommentService $recordCommentService;
    private DnsBackendProvider $backendProvider;
    private LegacyLogger $auditLogger;
    private IpAddressRetriever $ipAddressRetriever;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = $this->createZoneRepository();
        $this->recordRepository = new RecordRepository($this->db, $this->getConfig());
        $this->permissionService = new ApiPermissionService($this->db);
        $this->backendProvider = DnsBackendProviderFactory::create($this->db, $this->getConfig(), $this->logger);

        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig(), $this->backendProvider);
        $this->recordCommentService = new RecordCommentService($recordCommentRepository, $this->backendProvider);

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

        $this->auditLogger = new LegacyLogger($this->db);
        $this->ipAddressRetriever = new IpAddressRetriever($_SERVER);
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
                            new OA\Property(property: 'id', oneOf: [new OA\Schema(type: 'integer'), new OA\Schema(type: 'string')], example: 1),
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

            // Filter out ENT (Empty Non-Terminal) records created by PowerDNS for RFC 8020 compliance.
            // These records have NULL/empty type and are not user-manageable.
            // Note: Repository already filters these, but kept as defensive measure.
            $validRecords = array_filter($records, function ($record) {
                return !empty($record['type']) && !empty($record['name']);
            });

            // Format record data
            $formattedRecords = array_map(function ($record) {
                return [
                    'id' => $this->formatRecordId($record['id']),
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'content' => $this->stripTxtQuotes($record['content'], $record['type']),
                    'ttl' => (int)$record['ttl'],
                    'priority' => isset($record['prio']) ? (int)$record['prio'] : null,
                    'disabled' => isset($record['disabled']) ? (bool)DbCompat::boolFromDb($record['disabled']) : false,
                    'auth' => isset($record['auth']) ? (bool)DbCompat::boolFromDb($record['auth']) : true
                ];
            }, $validRecords);

            return $this->returnApiResponse($formattedRecords, true, 'Records retrieved successfully', 200);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZonesRecordsController::listRecords', 'Failed to retrieve records');
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
        schema: new OA\Schema(type: 'string')
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
                                new OA\Property(property: 'id', oneOf: [new OA\Schema(type: 'integer'), new OA\Schema(type: 'string')], example: 1),
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
            $recordId = $this->pathParameters['record_id'];
            if (ctype_digit($recordId)) {
                $recordId = (int)$recordId;
            }

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
                'id' => $this->formatRecordId($record['id']),
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
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZonesRecordsController::getRecord', 'Failed to retrieve record');
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
                        new OA\Property(property: 'record_id', oneOf: [new OA\Schema(type: 'integer'), new OA\Schema(type: 'string')], example: 456),
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

            // If validation passes, insert the record via backend provider
            // Wrap insert + SOA update in a transaction for atomicity (SQL backend only).
            // API backend polls the DB for the new record ID after the HTTP call;
            // opening a transaction before that would hide the row due to MVCC snapshot isolation.
            $useTransaction = !$this->backendProvider->isApiBackend();
            if ($useTransaction) {
                $this->db->beginTransaction();
            }

            $newRecordId = $this->insertRecordViaBackend($zoneId, $normalizedName, $type, $validatedContent, $validatedTtl, $validatedPriority, $disabled);

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

            // Fetch the newly created record
            $newRecord = $this->recordRepository->getRecordById($newRecordId);

            // Create PTR record if requested and record type is A or AAAA
            $ptrCreated = false;
            $ptrMessage = '';
            if ($createPtr && ($type === 'A' || $type === 'AAAA')) {
                try {
                    $dnsRecord = new DnsRecord($this->db, $this->getConfig());
                    $reverseRecordCreator = new ReverseRecordCreator($this->db, $this->getConfig(), $this->auditLogger, $dnsRecord, $this->recordCommentService, $this->createDnsBackendProvider());

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
                    $this->logger->error('PTR record creation failed: {error}', ['error' => $e->getMessage()]);
                }
            }

            $responseData = [
                'id' => $newRecord ? $this->formatRecordId($newRecord['id']) : null,
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

            $this->auditLogger->logInfo(sprintf(
                'client_ip:%s user_id:%d operation:api_add_record zone_id:%d type:%s name:%s',
                $this->ipAddressRetriever->getClientIp(),
                $userId,
                $zoneId,
                $type,
                $name
            ), $zoneId);

            $message = 'Record created successfully' . $ptrMessage;
            return $this->returnApiResponse(['record' => $responseData], true, $message, 201);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->handleException($e, 'ZonesRecordsController::createRecord', 'Failed to create record');
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
        schema: new OA\Schema(type: 'string')
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
                                new OA\Property(property: 'id', oneOf: [new OA\Schema(type: 'integer'), new OA\Schema(type: 'string')], example: 1),
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
            $recordId = $this->pathParameters['record_id'] ?? '';
            if (ctype_digit($recordId)) {
                $recordId = (int)$recordId;
            }

            if ($zoneId <= 0 || !$recordId) {
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
                'id' => $this->formatRecordId($updatedRecord['id']),
                'zone_id' => $zoneId,
                'name' => DnsHelper::stripZoneSuffix($updatedRecord['name'], $zoneName),
                'type' => $updatedRecord['type'],
                'content' => $this->stripTxtQuotes($updatedRecord['content'], $updatedRecord['type']),
                'ttl' => (int)$updatedRecord['ttl'],
                'priority' => isset($updatedRecord['prio']) ? (int)$updatedRecord['prio'] : 0,
                'disabled' => isset($updatedRecord['disabled']) ? (bool)DbCompat::boolFromDb($updatedRecord['disabled']) : false,
                'auth' => isset($updatedRecord['auth']) ? (bool)DbCompat::boolFromDb($updatedRecord['auth']) : true
            ];

            $this->auditLogger->logInfo(sprintf(
                'client_ip:%s user_id:%d operation:api_edit_record zone_id:%d record_id:%s',
                $this->ipAddressRetriever->getClientIp(),
                $userId,
                $zoneId,
                $recordId
            ), $zoneId);

            return $this->returnApiResponse(['record' => $formattedRecord], true, 'Record updated successfully', 200);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZonesRecordsController::updateRecord', 'Failed to update record');
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
        schema: new OA\Schema(type: 'string')
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
            $recordId = $this->pathParameters['record_id'] ?? '';
            if (ctype_digit($recordId)) {
                $recordId = (int)$recordId;
            }

            if ($zoneId <= 0 || !$recordId) {
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

            $this->auditLogger->logInfo(sprintf(
                'client_ip:%s user_id:%d operation:api_delete_record zone_id:%d record_id:%s',
                $this->ipAddressRetriever->getClientIp(),
                $userId,
                $zoneId,
                $recordId
            ), $zoneId);

            return $this->returnApiResponse(null, true, 'Record deleted successfully', 204);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZonesRecordsController::deleteRecord', 'Failed to delete record');
        }
    }

    /**
     * Format a record ID preserving encoded string IDs from API backend.
     *
     * @param mixed $id Record ID (int for SQL, encoded string for API)
     * @return int|string
     */
    private function formatRecordId(mixed $id): int|string
    {
        return ctype_digit((string)$id) ? (int)$id : $id;
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
     * @return int|string|null The new record ID, or null on failure
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
