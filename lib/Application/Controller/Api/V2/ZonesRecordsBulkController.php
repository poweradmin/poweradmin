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
 * RESTful API controller for bulk zone record operations
 *
 * Allows creating, updating, or deleting multiple records in a single
 * atomic transaction, improving performance and ensuring consistency.
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
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZonesRecordsBulkController extends PublicApiController
{
    private ZoneRepositoryInterface $zoneRepository;
    private RecordRepositoryInterface $recordRepository;
    private RecordManagerInterface $recordManager;
    private SOARecordManagerInterface $soaRecordManager;
    private ApiPermissionService $permissionService;
    private RecordCommentService $recordCommentService;
    private DnsBackendProvider $backendProvider;
    private RecordChangeLogger $changeLogger;
    private ReverseTtlResolver $reverseTtlResolver;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->backendProvider = DnsBackendProviderFactory::create($this->db, $this->getConfig(), $this->logger);
        $this->reverseTtlResolver = $this->createReverseTtlResolver();
        $repositoryFactory = $this->getRepositoryFactory($this->backendProvider);
        $this->zoneRepository = $this->createZoneRepository();
        $this->recordRepository = $repositoryFactory->createRecordRepository();
        $this->permissionService = new ApiPermissionService($this->db);

        $recordCommentRepository = $repositoryFactory->createRecordCommentRepository();
        $this->recordCommentService = new RecordCommentService($recordCommentRepository);

        $this->soaRecordManager = DnsServiceFactory::createSOARecordManager($this->db, $this->getConfig(), $this->backendProvider);
        $this->recordManager = DnsServiceFactory::createRecordManager($this->db, $this->getConfig(), $this->backendProvider);
        $this->changeLogger = new RecordChangeLogger($this->db);
    }

    /**
     * Handle bulk record requests
     */
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'POST' => $this->bulkRecordOperations(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    // Each bulk item carries its own action, so the operation scope is enforced
    // per action in bulkRecordOperations(), not by the request's HTTP method.
    protected function requiredApiKeyOperations(): array
    {
        return [];
    }

    /**
     * Perform bulk record operations (create, update, delete)
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/v2/zones/{id}/records/bulk',
        operationId: 'v2BulkRecordOperations',
        summary: 'Perform bulk record operations',
        description: 'Create, update, or delete multiple records in a single request.',
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
        description: 'Bulk operations data',
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'operations',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'action', type: 'string', enum: ['create', 'update', 'delete'], example: 'create', description: 'Operation type'),
                            new OA\Property(property: 'id', oneOf: [new OA\Schema(type: 'integer'), new OA\Schema(type: 'string')], example: 123, description: 'Record ID (required for update/delete)'),
                            new OA\Property(property: 'name', type: 'string', example: 'www', description: 'Record name (required for create)'),
                            new OA\Property(property: 'type', type: 'string', example: 'A', description: 'Record type (required for create)'),
                            new OA\Property(property: 'content', type: 'string', example: '192.168.1.1', description: 'Record content (required for create/update)'),
                            new OA\Property(property: 'ttl', type: 'integer', example: 3600, description: 'TTL in seconds'),
                            new OA\Property(property: 'priority', type: 'integer', example: 10, description: 'Priority for MX/SRV'),
                            new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag')
                        ],
                        type: 'object'
                    ),
                    description: 'Array of operations to perform'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Bulk operations completed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Bulk operations completed successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'total_operations', type: 'integer', example: 10),
                        new OA\Property(property: 'created', type: 'integer', example: 5),
                        new OA\Property(property: 'updated', type: 'integer', example: 3),
                        new OA\Property(property: 'deleted', type: 'integer', example: 2),
                        new OA\Property(property: 'failed', type: 'integer', example: 0),
                        new OA\Property(
                            property: 'errors',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            description: 'Array of error messages (if any)'
                        )
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Invalid request data'
    )]
    private function bulkRecordOperations(): JsonResponse
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

            // Check if user has permission to edit records in this zone
            $zoneType = $zone['type'] ?? null;
            if (!$this->permissionService->canEditZoneContent($userId, $zoneId, $zoneType)) {
                return $this->returnApiError($this->zoneEditDeniedMessage($zoneType), 403);
            }

            $input = json_decode($this->request->getContent(), true);
            if (!$input || !isset($input['operations']) || !is_array($input['operations'])) {
                return $this->returnApiError("Field 'operations' is required and must be an array", 400);
            }

            if (empty($input['operations'])) {
                return $this->returnApiError("At least one operation is required", 400);
            }

            // The HTTP method (POST) does not determine the operation here: each item
            // carries its own action. Enforce the API key's operation scope per action
            // before mutating anything, so a create-only key cannot update or delete.
            $scope = $this->getApiKeyScope();
            foreach ($input['operations'] as $operation) {
                $action = strtolower($operation['action'] ?? '');
                $operationType = match ($action) {
                    'create' => ApiKeyScope::OP_CREATE,
                    'update' => ApiKeyScope::OP_UPDATE,
                    'delete' => ApiKeyScope::OP_DELETE,
                    default => null,
                };
                if ($operationType !== null && !$scope->isOperationTypeAllowed($operationType)) {
                    return $this->returnApiError(
                        "Forbidden: this API key is not permitted to perform the {$action} operation",
                        403
                    );
                }
            }

            // Start transaction for atomic operations (SQL backend only).
            // API backend polls the DB for new record IDs after HTTP calls;
            // an open transaction hides those rows due to MVCC snapshot isolation.
            $useTransaction = !$this->backendProvider->isApiBackend();
            if ($useTransaction) {
                $this->db->beginTransaction();
            }

            $results = [
                'total_operations' => count($input['operations']),
                'created' => 0,
                'updated' => 0,
                'deleted' => 0,
                'failed' => 0,
                'errors' => []
            ];

            // Track if any non-SOA records were modified (for SOA serial update logic)
            $nonSOARecordModified = false;

            try {
                foreach ($input['operations'] as $index => $operation) {
                    $action = strtolower($operation['action'] ?? '');

                    try {
                        switch ($action) {
                            case 'create':
                                $recordType = $this->performCreateOperation($zoneId, $operation, $zoneType);
                                $results['created']++;
                                if ($recordType !== 'SOA') {
                                    $nonSOARecordModified = true;
                                }
                                break;

                            case 'update':
                                $recordType = $this->performUpdateOperation($zoneId, $operation, $zoneType);
                                $results['updated']++;
                                if ($recordType !== 'SOA') {
                                    $nonSOARecordModified = true;
                                }
                                break;

                            case 'delete':
                                $recordType = $this->performDeleteOperation($zoneId, $operation, $zoneType);
                                $results['deleted']++;
                                if ($recordType !== 'SOA') {
                                    $nonSOARecordModified = true;
                                }
                                break;

                            default:
                                throw new ApiErrorException("Invalid action: $action. Must be 'create', 'update', or 'delete'", 400);
                        }
                    } catch (\Throwable $e) {
                        $results['failed']++;
                        $results['errors'][] = "Operation $index ($action): " . $e->getMessage();

                        // Rollback on any error for atomicity
                        throw $e;
                    }
                }

                // Update SOA serial only if non-SOA records were modified
                // This prevents overwriting user-supplied SOA serial values
                if ($nonSOARecordModified) {
                    $this->soaRecordManager->updateSOASerial($zoneId);
                }

                if ($useTransaction) {
                    $this->db->commit();
                }

                $message = 'Bulk operations completed successfully';
                if ($results['failed'] > 0) {
                    $message = 'Some operations failed';
                }

                return $this->returnApiResponse($results, true, $message, 200);
            } catch (\Throwable $e) {
                if ($useTransaction) {
                    $this->db->rollBack();

                    // Reset counters - rollback means no changes were persisted
                    $results['created'] = 0;
                    $results['updated'] = 0;
                    $results['deleted'] = 0;
                }

                throw $e;
            }
        } catch (ApiErrorException $e) {
            // Client validation errors - return detailed error response with appropriate 4xx status code
            $statusCode = (int) ($e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 400);

            // Return detailed error information as advertised in OpenAPI spec
            if (!empty($results['errors'])) {
                return $this->returnApiResponse($results, false, 'Bulk operations failed', $statusCode);
            }

            return $this->returnApiError('Bulk operations failed: ' . $e->getMessage(), $statusCode);
        } catch (\Throwable $e) {
            // Server errors - return 500
            return $this->returnApiError('Bulk operations failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Perform create operation
     *
     * @param int $zoneId Zone ID
     * @param array $operation Operation data
     * @return string The record type that was created
     * @throws Exception If operation fails
     */
    private function performCreateOperation(int $zoneId, array $operation, ?string $zoneType = null): string
    {
        // Validate required fields
        $requiredFields = ['name', 'type', 'content'];
        foreach ($requiredFields as $field) {
            $value = $this->inputString($operation, $field);
            if ($value === null || trim($value) === '') {
                throw new ApiErrorException("Field '$field' is required for create operation", 400);
            }
        }

        $name = trim($this->inputString($operation, 'name', ''));
        $type = strtoupper(trim($this->inputString($operation, 'type', '')));
        $content = trim($this->inputString($operation, 'content', ''));

        // Get zone name (needed up-front so the TTL fallback can be type-aware).
        $repositoryFactory = $this->getRepositoryFactory($this->backendProvider);
        $domainRepository = $repositoryFactory->createDomainRepository();
        $zoneName = $domainRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            throw new ApiErrorException('Zone not found', 404);
        }
        $isReverseZone = DnsHelper::isReverseZone($zoneName);

        $ttl = $this->inputInt($operation, 'ttl', $this->reverseTtlResolver->resolveTtlForType($type, $isReverseZone));
        $priority = $this->inputInt($operation, 'priority', 0);
        $disabled = $this->inputIntFromBool($operation, 'disabled', 0);

        if ($ttl === null || $priority === null || $disabled === null) {
            throw new ApiErrorException('Fields ttl, priority, and disabled must be numeric', 400);
        }

        // Block SOA/NS edits for users limited to zone_content_edit_own_as_client
        if (!$this->permissionService->canEditZoneRecord($this->getAuthenticatedUserId(), $zoneId, $type, $zoneType)) {
            throw new ApiErrorException('You do not have permission to edit this record type', 403);
        }

        // Validate TTL
        if ($ttl < 1) {
            throw new ApiErrorException('TTL must be greater than 0', 400);
        }

        // Convert name to FQDN
        $fqdn = DnsHelper::restoreZoneSuffix($name, $zoneName);

        // Normalize the hostname
        $hostnameValidator = new HostnameValidator($this->getConfig());
        $normalizedName = $hostnameValidator->normalizeRecordName($fqdn, $zoneName);

        // Format content, with V2 API always auto-quoting TXT records
        $content = $this->formatV2RecordContent($type, $content);

        // Validate the record (pass backendProvider so CNAME/violation checks use correct backend)
        $validationService = DnsServiceFactory::createDnsRecordValidationService($this->db, $this->getConfig(), $this->backendProvider);
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
            throw new ApiErrorException($validationResult->getFirstError(), 400);
        }

        // Get validated values
        $validatedData = $validationResult->getData();
        $validatedContent = $validatedData['content'] ?? $content;
        $validatedTtl = $validatedData['ttl'] ?? $ttl;
        $validatedPriority = $validatedData['prio'] ?? $priority;

        // Insert record via backend provider
        $newRecordId = $this->insertRecordViaBackend($zoneId, $normalizedName, $type, $validatedContent, $validatedTtl, $validatedPriority, $disabled);
        if ($newRecordId === null) {
            throw new Exception('Failed to create record');
        }

        try {
            $zoneName = $this->backendProvider->getZoneNameById($zoneId);
            $this->changeLogger->logRecordCreate([
                'id' => $newRecordId,
                'name' => $normalizedName,
                'type' => $type,
                'content' => $validatedContent,
                'ttl' => $validatedTtl,
                'prio' => $validatedPriority,
                'disabled' => (bool) $disabled,
                'zone_name' => is_string($zoneName) ? $zoneName : null,
            ], $zoneId);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to write bulk record create log: {error}', ['error' => $e->getMessage()]);
        }

        return $type;
    }

    /**
     * Perform update operation
     *
     * @param int $zoneId Zone ID
     * @param array $operation Operation data
     * @return string The record type that was updated
     * @throws Exception If operation fails
     */
    private function performUpdateOperation(int $zoneId, array $operation, ?string $zoneType = null): string
    {
        if (!isset($operation['id'])) {
            throw new ApiErrorException("Field 'id' is required for update operation", 400);
        }

        $recordId = RecordIdHelper::normalizeId($operation['id']);

        // Get existing record
        $existingRecord = $this->recordRepository->getRecordById($recordId);
        if (!$existingRecord || $existingRecord['domain_id'] != $zoneId) {
            throw new ApiErrorException("Record not found in this zone", 404);
        }

        // Block SOA/NS edits for users limited to zone_content_edit_own_as_client
        $userId = $this->getAuthenticatedUserId();
        if (!$this->permissionService->canEditZoneRecord($userId, $zoneId, (string)$existingRecord['type'], $zoneType)) {
            throw new ApiErrorException('You do not have permission to edit this record type', 403);
        }
        $newType = strtoupper(trim((string)($operation['type'] ?? $existingRecord['type'])));
        if (
            $newType !== strtoupper((string)$existingRecord['type'])
            && !$this->permissionService->canEditZoneRecord($userId, $zoneId, $newType, $zoneType)
        ) {
            throw new ApiErrorException('You do not have permission to edit this record type', 403);
        }

        // Prepare record data for update
        $name = $this->inputString($operation, 'name', $existingRecord['name']);
        $type = $this->inputString($operation, 'type', $existingRecord['type']);
        $content = $this->inputString($operation, 'content', $existingRecord['content']);
        $ttl = $this->inputInt($operation, 'ttl', (int)$existingRecord['ttl']);
        $prio = $this->inputInt($operation, 'priority', (int)($existingRecord['prio'] ?? 0));
        $disabled = $this->inputIntFromBool($operation, 'disabled', (int)($existingRecord['disabled'] ?? 0));
        if ($name === null || $type === null || $content === null || $ttl === null || $prio === null || $disabled === null) {
            throw new ApiErrorException('Invalid field types in request body', 400);
        }
        // Format content the same way create does so TXT records round-trip
        // (GET strips the quotes V2 adds; an update echoing GET output must re-quote).
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

        // Pre-validate so a rejected record reports the specific reason as 400.
        // editRecord() otherwise swallows the validation message, leaving only a 500.
        $validationService = DnsServiceFactory::createDnsRecordValidationService($this->db, $this->getConfig(), $this->backendProvider);
        $editZoneName = $this->createDomainRepository()->getDomainNameById($zoneId);
        $normalizedEditName = (new HostnameValidator($this->getConfig()))->normalizeRecordName($recordData['name'], $editZoneName);
        $editValidation = $validationService->validateRecord(
            $recordId,
            $zoneId,
            $recordData['type'],
            $recordData['content'],
            $normalizedEditName,
            $recordData['prio'],
            $recordData['ttl'],
            $this->getConfig()->get('dns', 'hostmaster'),
            (int)$this->getConfig()->get('dns', 'ttl')
        );
        if (!$editValidation->isValid()) {
            throw new ApiErrorException($editValidation->getFirstError(), 400);
        }

        // Use RecordManager to edit the record
        if (!$this->recordManager->editRecord($recordData)) {
            throw new Exception('Failed to update record');
        }

        return $recordData['type'];
    }

    /**
     * Perform delete operation
     *
     * @param int $zoneId Zone ID
     * @param array $operation Operation data
     * @return string The record type that was deleted
     * @throws Exception If operation fails
     */
    private function performDeleteOperation(int $zoneId, array $operation, ?string $zoneType = null): string
    {
        if (!isset($operation['id'])) {
            throw new ApiErrorException("Field 'id' is required for delete operation", 400);
        }

        $recordId = RecordIdHelper::normalizeId($operation['id']);

        // Verify record exists in this zone
        $existingRecord = $this->recordRepository->getRecordById($recordId);
        if (!$existingRecord || $existingRecord['domain_id'] != $zoneId) {
            throw new ApiErrorException("Record not found in this zone", 404);
        }

        // Store record type before deletion (for SOA serial update logic)
        $recordType = $existingRecord['type'];

        // Block SOA/NS deletes for users limited to zone_content_edit_own_as_client
        if (!$this->permissionService->canEditZoneRecord($this->getAuthenticatedUserId(), $zoneId, (string)$recordType, $zoneType)) {
            throw new ApiErrorException('You do not have permission to delete this record type', 403);
        }

        // Use RecordManager to delete the record
        if (!$this->recordManager->deleteRecord($recordId)) {
            throw new Exception('Failed to delete record');
        }

        // Clean up per-record comment
        $this->recordCommentService->deleteCommentByRecordId($recordId);

        // Clean up legacy RRset comments if no similar records remain
        $similarRecords = $this->recordRepository->getRRSetRecords($zoneId, $existingRecord['name'], $recordType);
        if (empty($similarRecords)) {
            $this->recordCommentService->deleteComment($zoneId, $existingRecord['name'], $recordType);
        }

        return $recordType;
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
}
