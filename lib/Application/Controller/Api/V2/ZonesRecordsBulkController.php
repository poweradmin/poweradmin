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
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Repository\RecordLookupInterface;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Poweradmin\Application\Http\RefusalStatus;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * POST /api/v2/zones/{id}/records/bulk: applies a list of create, update and delete record operations.
 */
class ZonesRecordsBulkController extends PublicApiController
{
    private ZoneReadRepositoryInterface $zoneRepository;
    private RecordLookupInterface $recordRepository;
    private RecordManagerInterface $recordManager;
    private ApiPermissionService $apiPermissionService;
    private BackendCapabilitiesInterface $backendProvider;
    private ReverseTtlResolver $reverseTtlResolver;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->backendProvider = $this->services()->dnsBackendProvider();
        $this->reverseTtlResolver = $this->services()->reverseTtlResolver();
        $this->zoneRepository = $this->services()->zoneRepository();
        $this->recordRepository = $this->services()->recordRepository();
        $this->apiPermissionService = $this->services()->apiPermissionService();

        $this->recordManager = $this->services()->recordManager();
    }

    /**
     * Handle bulk record requests
     */
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'POST' => $this->bulkRecordOperations(),
            default => $this->methodNotAllowed(['POST']),
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
                ),
                new OA\Property(
                    property: 'comment',
                    type: 'string',
                    example: 'ticket-4821: move the web tier',
                    description: 'Reason for this change, recorded in the change log.'
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
            if (!$this->apiPermissionService->canEditZoneContent($userId, $zoneId, $zoneType)) {
                return $this->returnApiError($this->zoneEditDeniedMessage($zoneType), 403);
            }

            if (($approval = $this->refuseWhenChangeRequestRequired($this->apiPermissionService, $userId, $zoneId)) !== null) {
                return $approval;
            }

            $input = $this->getValidatedJsonBody() ?? [];
            if (!isset($input['operations']) || !is_array($input['operations'])) {
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
                if ($operationType === null) {
                    return $this->returnApiError("Invalid action: $action. Must be 'create', 'update', or 'delete'", 400);
                }
                if (!$scope->isOperationTypeAllowed($operationType)) {
                    return $this->returnApiError(
                        "Forbidden: this API key is not permitted to perform the {$action} operation",
                        403
                    );
                }
            }

            // One request, one changeset, carrying the optional reason the caller gave.
            $changeComment = (string)($input['comment'] ?? '');
            if (trim($changeComment) === '' && $this->services()->recordChangeLog()->changeCommentRequired()) {
                return $this->returnApiError("Field 'comment' is required: this installation requires a reason for every change", 400);
            }

            $useTransaction = $this->backendProvider->supportsLocalWriteTransaction();
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

            // A full closure: an arrow function would copy $results and the outer catch
            // would report empty errors.
            return $this->services()->recordChangeLog()->withChangeset($zoneId, $changeComment, function () use ($zoneId, $input, $zoneType, $zone, $useTransaction, &$results): JsonResponse {
                return $this->applyOperations($zoneId, $input['operations'], $zoneType, $zone['name'] ?? null, $useTransaction, $results);
            });
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
     * Runs the operations inside the open transaction. Any failure rolls back, zeroes the
     * counters and rethrows so the caller can report which operation failed.
     *
     * @param array<int, array<string, mixed>> $operations
     * @param array<string, mixed> $results Counters and errors, read by the caller after a throw
     */
    private function applyOperations(int $zoneId, array $operations, ?string $zoneType, ?string $zoneName, bool $useTransaction, array &$results): JsonResponse
    {
        // Only non-SOA changes bump the serial, so a user-supplied SOA serial stays.
        $nonSOARecordModified = false;
        try {
            foreach ($operations as $index => $operation) {
                $action = strtolower($operation['action'] ?? '');

                try {
                    $recordType = match ($action) {
                        'create' => $this->performCreateOperation($zoneId, $operation, $zoneType, $zoneName),
                        'update' => $this->performUpdateOperation($zoneId, $operation, $zoneType, $zoneName),
                        'delete' => $this->performDeleteOperation($zoneId, $operation, $zoneType, $zoneName),
                        default => throw new ApiErrorException("Invalid action: $action. Must be 'create', 'update', or 'delete'", 400),
                    };
                    $results[$action . 'd']++;
                    if ($recordType !== 'SOA') {
                        $nonSOARecordModified = true;
                    }
                } catch (\Throwable $e) {
                    $results['failed']++;
                    $results['errors'][] = "Operation $index ($action): " . $e->getMessage();

                    // Rollback on any error for atomicity
                    throw $e;
                }
            }

            // The serial moves with the records, inside the transaction; the rectify
            // waits for the commit since PowerDNS reads committed rows.
            if ($nonSOARecordModified) {
                $this->services()->soaRecordManager()->updateSOASerial($zoneId);
            }
            if ($useTransaction) {
                $this->db->commit();
            }
            $this->recordManager->finalizeZone($zoneId, false);

            $this->services()->auditService()->logApiBulkRecords($zoneId, $results['total_operations']);

            // Any failed operation rethrows above, so reaching here means all succeeded
            return $this->returnApiResponse($results, true, 'Bulk operations completed successfully', 200);
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
    }

    /**
     * Perform create operation
     *
     * @param int $zoneId Zone ID
     * @param array $operation Operation data
     * @return string The record type that was created
     * @throws Exception If operation fails
     */
    private function performCreateOperation(int $zoneId, array $operation, ?string $zoneType = null, ?string $zoneName = null): string
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

        if ($zoneName === null) {
            throw new ApiErrorException('Zone not found', 404);
        }
        $isReverseZone = DnsHelper::isReverseZoneName($zoneName);

        $ttl = $this->inputInt($operation, 'ttl', $this->reverseTtlResolver->resolveTtlForType($type, $isReverseZone));
        $priority = $this->inputInt($operation, 'priority', 0);
        $disabled = $this->inputIntFromBool($operation, 'disabled', 0);

        if ($ttl === null || $priority === null || $disabled === null) {
            throw new ApiErrorException('Fields ttl, priority, and disabled must be numeric', 400);
        }

        // Validate TTL
        if ($ttl < 0) {
            throw new ApiErrorException('TTL must not be negative', 400);
        }

        // Convert name to FQDN
        $fqdn = $this->normalizeV2RecordName($name, $zoneName);

        // Normalized so the record-type permission check sees the FQDN
        $hostnameValidator = new HostnameValidator(HostnamePolicy::fromConfig($this->getConfig()));
        $normalizedName = strtolower($hostnameValidator->normalizeRecordName($fqdn, $zoneName));

        // Block SOA/NS edits for users limited to zone_content_edit_own_as_client;
        // checked after normalization so the subzone NS exemption sees the FQDN
        if (!$this->apiPermissionService->canEditZoneRecord($this->getAuthenticatedUserId(), $zoneId, $type, $zoneType, $normalizedName, $zoneName)) {
            throw new ApiErrorException('You do not have permission to edit this record type', 403);
        }

        // Format content, with V2 API always auto-quoting TXT records
        $content = $this->formatV2RecordContent($type, $content);

        // Validation, the duplicate check and the change log are the record manager's;
        // the batch bumps the serial and rectifies once at the end.
        $created = $this->recordManager->addRecordGetId($zoneId, $normalizedName, $type, $content, $ttl, $priority, $disabled, false);
        if (!$created->success) {
            throw new ApiErrorException($this->recordWriteErrorMessage($created, 'Failed to create record'), RefusalStatus::of($created->refusal));
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
    private function performUpdateOperation(int $zoneId, array $operation, ?string $zoneType = null, ?string $zoneName = null): string
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
        $hostnameValidator = new HostnameValidator(HostnamePolicy::fromConfig($this->getConfig()));
        if (!$this->apiPermissionService->canEditZoneRecord($userId, $zoneId, (string)$existingRecord['type'], $zoneType, (string)$existingRecord['name'], $zoneName)) {
            throw new ApiErrorException('You do not have permission to edit this record type', 403);
        }
        // Block changing the record into one the user may not manage, e.g.
        // retyping to SOA/NS or renaming a subzone NS onto the zone apex
        $newType = strtoupper(trim((string)($operation['type'] ?? $existingRecord['type'])));
        $newName = $hostnameValidator->normalizeRecordName(trim((string)($operation['name'] ?? $existingRecord['name'])), (string)$zoneName);
        if (!$this->apiPermissionService->canEditZoneRecord($userId, $zoneId, $newType, $zoneType, $newName, $zoneName)) {
            throw new ApiErrorException('You do not have permission to edit this record type', 403);
        }

        // Prepare record data for update
        $name = $this->inputString($operation, 'name', $existingRecord['name']);
        $type = $this->inputString($operation, 'type', $existingRecord['type']);
        $content = $this->inputString($operation, 'content', $existingRecord['content']);
        $ttl = $this->inputInt($operation, 'ttl', (int)$existingRecord['ttl']);
        $prio = $this->inputInt($operation, 'priority', (int)($existingRecord['prio'] ?? 0));
        $disabled = $this->inputIntFromBool($operation, 'disabled', !empty($existingRecord['disabled']) ? 1 : 0);
        if ($name === null || $type === null || $content === null || $ttl === null || $prio === null || $disabled === null) {
            throw new ApiErrorException('Invalid field types in request body', 400);
        }
        // A TTL of 0 is RFC-valid ("do not cache") and TTLValidator accepts it, so
        // only a negative value is refused here. An update that leaves ttl out
        // inherits the stored value and must not be rejected for it.
        if ($ttl < 0) {
            throw new ApiErrorException('TTL must not be negative', 400);
        }
        if ($disabled !== 0 && $disabled !== 1) {
            throw new ApiErrorException('Disabled field must be 0 or 1', 400);
        }
        $name = $this->normalizeV2RecordName($name, (string)$zoneName);
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

        $result = $this->recordManager->editRecord($recordData, false);
        if (!$result->success) {
            // Backend faults keep the generic contract string; refusals carry their reason
            if ($result->refusal === Refusal::BACKEND_FAILURE) {
                throw new Exception('Failed to update record');
            }
            throw new ApiErrorException((string)$result->message, RefusalStatus::of($result->refusal));
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
    private function performDeleteOperation(int $zoneId, array $operation, ?string $zoneType = null, ?string $zoneName = null): string
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

        // The stored type decides which records a client-limited caller may touch
        $recordType = $existingRecord['type'];

        // Block SOA/NS deletes for users limited to zone_content_edit_own_as_client
        if (!$this->apiPermissionService->canEditZoneRecord($this->getAuthenticatedUserId(), $zoneId, (string)$recordType, $zoneType, (string)$existingRecord['name'], $zoneName)) {
            throw new ApiErrorException('You do not have permission to delete this record type', 403);
        }

        $result = $this->recordManager->deleteRecord($recordId, false);
        if (!$result->success) {
            // Backend faults keep the generic contract string; refusals carry their reason
            if ($result->refusal === Refusal::BACKEND_FAILURE) {
                throw new Exception('Failed to delete record');
            }
            throw new ApiErrorException((string)$result->message, RefusalStatus::of($result->refusal));
        }

        return $recordType;
    }
}
