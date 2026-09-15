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

use Exception;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Infrastructure\Database\DbCompat;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * /api/v2/zones/{id}/records: lists, creates, updates and deletes single records in a zone.
 */
class ZonesRecordsController extends PublicApiController
{
    private ZoneReadRepositoryInterface $zoneRepository;
    private RecordRepositoryInterface $recordRepository;
    private RecordManagerInterface $recordManager;
    private ApiPermissionService $apiPermissionService;
    private ReverseTtlResolver $reverseTtlResolver;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->reverseTtlResolver = $this->createReverseTtlResolver();
        $this->zoneRepository = $this->createZoneRepository();
        $this->recordRepository = $this->createRecordRepository();
        $this->apiPermissionService = $this->createApiPermissionService();

        $this->recordManager = $this->createRecordManager();
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
                    properties: [
                        new OA\Property(
                            property: 'records',
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
                    ],
                    type: 'object'
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

            if (($scopeError = $this->enforceApiKeyZoneScope((int)$zoneId)) !== null) {
                return $scopeError;
            }

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to view this zone
            if (!$this->apiPermissionService->canViewZone($userId, $zoneId)) {
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

            return $this->returnApiResponse(['records' => $formattedRecords], true, 'Records retrieved successfully', 200);
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
            $recordId = RecordIdHelper::normalizeId($this->pathParameters['record_id']);

            if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
                return $scopeError;
            }

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to view this zone
            if (!$this->apiPermissionService->canViewZone($userId, $zoneId)) {
                return $this->returnApiError('You do not have permission to view this zone', 403);
            }

            // Get specific record
            $record = $this->recordRepository->getRecordById($recordId);
            if (!$record || $record['domain_id'] != $zoneId) {
                return $this->returnApiError('Record not found in this zone', 404);
            }

            $zoneName = $this->createDomainRepository()->getDomainNameById($zoneId);

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
    #[OA\Response(
        response: 409,
        description: 'An identical record (name, type and content) already exists',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'A record with this hostname, type, and content already exists'),
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

            if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
                return $scopeError;
            }

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to edit this zone
            if (!$this->apiPermissionService->canEditZoneContent($userId, $zoneId, $zone['type'] ?? null)) {
                return $this->returnApiError($this->zoneEditDeniedMessage($zone['type'] ?? null), 403);
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
            $originalContent = trim($this->inputString($input, 'content', ''));
            $content = $originalContent;
            $zoneName = $this->createDomainRepository()->getDomainNameById($zoneId);
            if ($zoneName === null) {
                return $this->returnApiError(_('Zone not found.'), 404);
            }
            $isReverseZone = DnsHelper::isReverseZoneName($zoneName);
            $ttl = $this->inputInt($input, 'ttl', $this->reverseTtlResolver->resolveTtlForType($type, $isReverseZone));
            $priority = $this->inputInt($input, 'priority', 0);
            $disabled = $this->inputIntFromBool($input, 'disabled', 0);
            $createPtr = $this->inputBool($input, 'create_ptr', false);

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

            // Punycode plus the zone suffix, so @ becomes the apex and IDN labels match storage
            $name = $this->normalizeV2RecordName($name, $zoneName);

            // Normalize the hostname so the record-type permission check sees the FQDN
            $hostnameValidator = new HostnameValidator($this->getConfig());
            $normalizedName = strtolower($hostnameValidator->normalizeRecordName($name, $zoneName));

            // Block SOA/NS edits for users limited to zone_content_edit_own_as_client;
            // checked after normalization so the subzone NS exemption sees the FQDN
            if (!$this->apiPermissionService->canEditZoneRecord($userId, $zoneId, $type, $zone['type'] ?? null, $normalizedName, $zoneName)) {
                return $this->returnApiError('You do not have permission to edit this record type', 403);
            }

            // Format content, with V2 API always auto-quoting TXT records
            $content = $this->formatV2RecordContent($type, $content);

            // Validation, the duplicate check, the serial bump, the change log and the
            // rectify are the record manager's, as on the web.
            $created = $this->recordManager->addRecordGetId($zoneId, $normalizedName, $type, $content, $ttl, $priority, $disabled);
            if (!$created->success) {
                return $this->returnApiError($this->recordWriteErrorMessage($created, 'Failed to create record'), $created->status);
            }
            $newRecordId = $created->recordId;

            // Fetch the newly created record; the stored values are what the manager validated
            $newRecord = $this->recordRepository->getRecordById($newRecordId);
            $validatedContent = (string)($newRecord['content'] ?? $content);
            $validatedTtl = (int)($newRecord['ttl'] ?? $ttl);
            $validatedPriority = (int)($newRecord['prio'] ?? $priority);

            // Create PTR record if requested and record type is A or AAAA
            $ptrCreated = false;
            $ptrMessage = '';
            if ($createPtr && ($type === 'A' || $type === 'AAAA')) {
                try {
                    $reverseRecordCreator = $this->createReverseRecordCreator();

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

            // Return stored content (not raw input) so POST and GET responses are consistent
            $storedContent = $newRecord ? $this->stripTxtQuotes($newRecord['content'], $type) : $originalContent;

            $responseData = [
                'id' => $newRecord ? $this->formatRecordId($newRecord['id']) : null,
                'zone_id' => $zoneId,
                'name' => DnsHelper::stripZoneSuffix($name, $zoneName),
                'type' => $type,
                'content' => $storedContent,
                'ttl' => $validatedTtl,
                'priority' => $validatedPriority,
                'disabled' => (bool)$disabled,
                'auth' => true,
                'ptr_created' => $ptrCreated
            ];

            $this->createAuditService()->logApiRecordAdd($zoneId, $name, $type, $content);

            $message = 'Record created successfully' . $ptrMessage;
            return $this->returnApiResponse(['record' => $responseData], true, $message, 201);
        } catch (\Throwable $e) {
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
                new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag (false = enabled, true = disabled)'),
                new OA\Property(property: 'update_ptr', type: 'boolean', example: false, description: 'Sync PTR record with new value (A/AAAA only). Default: false')
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
            $recordId = RecordIdHelper::normalizeId($this->pathParameters['record_id'] ?? '');

            if ($zoneId <= 0 || !$recordId) {
                return $this->returnApiError('Valid zone ID and record ID are required', 400);
            }

            if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
                return $scopeError;
            }

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to edit this zone
            if (!$this->apiPermissionService->canEditZoneContent($userId, $zoneId, $zone['type'] ?? null)) {
                return $this->returnApiError($this->zoneEditDeniedMessage($zone['type'] ?? null), 403);
            }

            // Get existing record
            $existingRecord = $this->recordRepository->getRecordById($recordId);
            if (!$existingRecord || $existingRecord['domain_id'] != $zoneId) {
                return $this->returnApiError('Record not found in this zone', 404);
            }

            // Block SOA/NS edits for users limited to zone_content_edit_own_as_client
            if (!$this->apiPermissionService->canEditZoneRecord($userId, $zoneId, (string)$existingRecord['type'], $zone['type'] ?? null, (string)$existingRecord['name'], $zone['name'] ?? null)) {
                return $this->returnApiError('You do not have permission to edit this record type', 403);
            }

            $input = json_decode($this->request->getContent(), true);
            if (!$input) {
                return $this->returnApiError('Invalid JSON in request body', 400);
            }

            // Block changing the record into one the user may not manage, e.g.
            // retyping to SOA/NS or renaming a subzone NS onto the zone apex
            $hostnameValidator = new HostnameValidator($this->getConfig());
            $newType = strtoupper(trim((string)($input['type'] ?? $existingRecord['type'])));
            $newName = $hostnameValidator->normalizeRecordName(trim((string)($input['name'] ?? $existingRecord['name'])), (string)$zone['name']);
            if (!$this->apiPermissionService->canEditZoneRecord($userId, $zoneId, $newType, $zone['type'] ?? null, $newName, $zone['name'] ?? null)) {
                return $this->returnApiError('You do not have permission to edit this record type', 403);
            }

            // Prepare record data for update - use existing values if not provided
            $name = $this->inputString($input, 'name', $existingRecord['name']);
            $type = $this->inputString($input, 'type', $existingRecord['type']);
            $content = $this->inputString($input, 'content', $existingRecord['content']);
            $ttl = $this->inputInt($input, 'ttl', (int)$existingRecord['ttl']);
            $prio = $this->inputInt($input, 'priority', (int)($existingRecord['prio'] ?? 0));
            $disabled = $this->inputIntFromBool($input, 'disabled', DbCompat::boolFromDb($existingRecord['disabled'] ?? 0));
            $updatePtr = $this->inputBool($input, 'update_ptr', false);
            if ($name === null || $type === null || $content === null || $ttl === null || $prio === null || $disabled === null) {
                return $this->returnApiError('Invalid field types in request body', 400);
            }
            $name = $this->normalizeV2RecordName($name, (string)$zone['name']);

            $oldType = strtoupper((string)$existingRecord['type']);
            $oldContent = (string)$existingRecord['content'];
            $oldName = (string)$existingRecord['name'];

            // Format content the same way create does so TXT records round-trip
            // (GET strips the quotes V2 adds; a PUT echoing GET output must re-quote).
            $content = $this->formatV2RecordContent($type, $content);

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

            $result = $this->recordManager->editRecord($recordData);
            if (!$result->success) {
                return $this->returnApiError($this->recordWriteErrorMessage($result, 'Failed to update record'), $result->status);
            }

            // Get the updated record to return.
            // In API mode the record ID may change when name/type/content/prio change,
            // so fall back to the submitted data if the old ID no longer resolves.
            $updatedRecord = $this->recordRepository->getRecordById($recordId);

            // Get zone name for stripping suffix
            $zoneName = $this->createDomainRepository()->getDomainNameById($zoneId);

            $ptrUpdated = false;
            $ptrMessage = '';
            if ($updatePtr && ($oldType === 'A' || $oldType === 'AAAA' || $recordData['type'] === 'A' || $recordData['type'] === 'AAAA')) {
                try {
                    $newName = $updatedRecord['name'] ?? $recordData['name'];
                    $newContent = $updatedRecord['content'] ?? $recordData['content'];

                    $reverseRecordCreator = $this->createReverseRecordCreator();

                    $ptrResult = $reverseRecordCreator->updateReverseRecord(
                        $oldType,
                        $oldContent,
                        $oldName,
                        $recordData['type'],
                        (string)$newContent,
                        $newName,
                        $zoneId,
                        (int)($updatedRecord['ttl'] ?? $recordData['ttl']),
                        (int)($updatedRecord['prio'] ?? $recordData['prio'])
                    );

                    if ($ptrResult['success']) {
                        $ptrUpdated = true;
                        $ptrMessage = ' ' . $ptrResult['message'];
                    } else {
                        $ptrMessage = ' PTR record update failed: ' . $ptrResult['message'];
                    }
                } catch (Exception $e) {
                    $ptrMessage = ' PTR record update failed: ' . $e->getMessage();
                    $this->logger->error('PTR record update failed: {error}', ['error' => $e->getMessage()]);
                }
            }

            if ($updatedRecord !== null) {
                $formattedRecord = [
                    'id' => $this->formatRecordId($updatedRecord['id']),
                    'zone_id' => $zoneId,
                    'name' => DnsHelper::stripZoneSuffix($updatedRecord['name'], $zoneName),
                    'type' => $updatedRecord['type'],
                    'content' => $this->stripTxtQuotes($updatedRecord['content'], $updatedRecord['type']),
                    'ttl' => (int)$updatedRecord['ttl'],
                    'priority' => isset($updatedRecord['prio']) ? (int)$updatedRecord['prio'] : 0,
                    'disabled' => isset($updatedRecord['disabled']) ? (bool)DbCompat::boolFromDb($updatedRecord['disabled']) : false,
                    'auth' => isset($updatedRecord['auth']) ? (bool)DbCompat::boolFromDb($updatedRecord['auth']) : true,
                    'ptr_updated' => $ptrUpdated
                ];
            } else {
                // The old id is dead once name/type/content/prio change, so look up
                // the one the record now has. Returning the old one would hand the
                // caller an identifier that 404s on its next request.
                $newRecordId = $this->recordRepository->getNewRecordId(
                    $zoneId,
                    $recordData['name'],
                    $recordData['type'],
                    $recordData['content']
                ) ?? $recordId;

                $formattedRecord = [
                    'id' => $this->formatRecordId($newRecordId),
                    'zone_id' => $zoneId,
                    'name' => DnsHelper::stripZoneSuffix($recordData['name'], $zoneName),
                    'type' => $recordData['type'],
                    'content' => $this->stripTxtQuotes($recordData['content'], $recordData['type']),
                    'ttl' => $recordData['ttl'],
                    'priority' => $recordData['prio'],
                    'disabled' => (bool)$recordData['disabled'],
                    'auth' => true,
                    'ptr_updated' => $ptrUpdated
                ];
            }

            $this->createAuditService()->logApiRecordEdit($zoneId, $formattedRecord['name'], $formattedRecord['type'], $formattedRecord['content']);

            return $this->returnApiResponse(['record' => $formattedRecord], true, 'Record updated successfully' . $ptrMessage, 200);
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
            $recordId = RecordIdHelper::normalizeId($this->pathParameters['record_id'] ?? '');

            if ($zoneId <= 0 || !$recordId) {
                return $this->returnApiError('Valid zone ID and record ID are required', 400);
            }

            if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
                return $scopeError;
            }

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check if user has permission to edit this zone
            if (!$this->apiPermissionService->canEditZoneContent($userId, $zoneId, $zone['type'] ?? null)) {
                return $this->returnApiError($this->zoneEditDeniedMessage($zone['type'] ?? null), 403);
            }

            // Verify record exists in this zone
            $existingRecord = $this->recordRepository->getRecordById($recordId);
            if (!$existingRecord || $existingRecord['domain_id'] != $zoneId) {
                return $this->returnApiError('Record not found in this zone', 404);
            }

            // The stored type decides which records a client-limited caller may touch
            $recordType = $existingRecord['type'];

            // Block SOA/NS deletes for users limited to zone_content_edit_own_as_client
            if (!$this->apiPermissionService->canEditZoneRecord($userId, $zoneId, (string)$recordType, $zone['type'] ?? null, (string)$existingRecord['name'], $zone['name'] ?? null)) {
                return $this->returnApiError('You do not have permission to delete this record type', 403);
            }

            $result = $this->recordManager->deleteRecord($recordId);
            if (!$result->success) {
                // Backend faults keep the generic contract string; refusals carry their reason
                return $this->returnApiError($result->status === 500 ? 'Failed to delete record' : (string)$result->message, $result->status);
            }

            $this->createAuditService()->logApiRecordDelete($zoneId, $existingRecord['name'] ?? '', $existingRecord['type'] ?? '', $existingRecord['content'] ?? '');

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
        return RecordIdHelper::normalizeId($id);
    }
}
