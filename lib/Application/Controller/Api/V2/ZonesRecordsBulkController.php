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
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
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
    private RecordRepositoryInterface $recordRepository;
    /** @var array<int, true> Reverse zones that received a PTR in this batch, finalized once at the end */
    private array $reverseZoneIds = [];
    private bool $dryRunActive = false;
    /** True for an API-backend dry run: the record manager only validates, nothing is written */
    private bool $validateOnly = false;
    /** @var array<string, true> Records an API-backend dry run has already accepted, to catch duplicates inside the batch */
    private array $batchCreated = [];
    /** @var array<string, true> Ids an API-backend dry run has deleted or re-keyed, which a real run no longer finds */
    private array $batchGoneIds = [];
    /** @var array<string, true> Name, type and content keys a dry run has freed by a delete or a re-key */
    private array $batchFreedKeys = [];
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

        $this->sendAndHalt($response);
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
        description: 'Create, update, or delete multiple records in a single request. '
            . 'With a local database the request is one transaction, PTR records in reverse zones '
            . 'included, and rolls back on the first failure. With the API backend there is no '
            . 'transaction: operations already applied, PTR records included, stay when a later one fails. '
            . 'With dry_run the same checks run and nothing is kept: no records, comments, PTR records, serial '
            . 'bump, change log or audit entry, and no change request. A local database runs the operations in '
            . 'the transaction and rolls it back, so it sees effects between operations. The API backend only '
            . 'validates each operation against the zone as it is now and tracks deletes, re-keying updates and '
            . 'duplicate creates inside the batch. It does not see a create followed by an update of that record, '
            . 'AAAA addresses spelled differently, a second create_ptr for the same address, or a CNAME added next '
            . 'to other data in the batch, and PowerDNS\'s own checks (for example the LUA record gate) are not run.',
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
                            new OA\Property(property: 'disabled', type: 'boolean', example: false, description: 'Disabled flag'),
                            new OA\Property(
                                property: 'comment',
                                type: 'string',
                                example: 'web server',
                                description: 'Record comment (create/update). On update an empty string clears it and '
                                    . 'omitting it leaves it unchanged. Not the change reason, which is the top-level '
                                    . 'comment. With the API backend the comment belongs to the RRset, so when several '
                                    . 'operations set one on the same name and type, the last one wins'
                            ),
                            new OA\Property(
                                property: 'create_ptr',
                                type: 'boolean',
                                example: false,
                                description: 'Create the PTR record (create only, A and AAAA only, ignored for other '
                                    . 'types). Needs a matching reverse zone the caller may edit. A PTR that cannot '
                                    . 'be created does not fail the operation; it is reported in data.warnings'
                            )
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
                ),
                new OA\Property(
                    property: 'dry_run',
                    type: 'boolean',
                    example: false,
                    description: 'Run every check and report what would happen, without writing anything. '
                        . 'Defaults to false. Gates apply as for a real call, including the required reason, '
                        . 'and a zone under change approval is still refused'
                )
            ]
        )
    )]
    #[OA\Response(response: 422, description: 'PowerDNS refused the write in API backend mode; the message carries its reason')]
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
                        new OA\Property(property: 'dry_run', type: 'boolean', example: true, description: 'Present and true only for a dry run, whose counts are what would have happened'),
                        new OA\Property(
                            property: 'errors',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            description: 'Array of error messages (if any)'
                        ),
                        new OA\Property(
                            property: 'warnings',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            example: ['Operation 2 (create): PTR record creation failed: There is no matching reverse-zone for: 1.2.0.192.in-addr.arpa.'],
                            description: 'Non-fatal problems, such as a create_ptr that could not be honoured; the record is still counted in created. Always present, empty when there are none'
                        )
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Invalid request data, or more operations than api.bulk_max_operations allows (1000 by default)'
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

            $dryRun = $this->inputBool($input, 'dry_run', false);
            if ($dryRun === null) {
                return $this->returnApiError("Field 'dry_run' must be a boolean", 400);
            }

            // One request runs every operation in a single transaction, so an unbounded list ties up the database
            $maxOperations = (int)$this->getConfig()->get('api', 'bulk_max_operations', 1000);
            if ($maxOperations > 0 && count($input['operations']) > $maxOperations) {
                return $this->returnApiError(sprintf('Too many operations: at most %d per request', $maxOperations), 400);
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
                $this->services()->transaction()->begin();
            }

            $results = [
                'total_operations' => count($input['operations']),
                'created' => 0,
                'updated' => 0,
                'deleted' => 0,
                'failed' => 0,
                'errors' => [],
                'warnings' => []
            ];
            if ($dryRun) {
                $results['dry_run'] = true;
            }

            // A full closure: an arrow function would copy $results and the outer catch
            // would report empty errors.
            return $this->services()->recordChangeLog()->withChangeset($zoneId, $changeComment, function () use ($zoneId, $input, $zoneType, $zone, $useTransaction, $dryRun, &$results): JsonResponse {
                return $this->applyOperations($zoneId, $input['operations'], $zoneType, $zone['name'] ?? null, $useTransaction, $results, $dryRun);
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
            return $this->handleException($e, 'ZonesRecordsBulkController::bulkRecordOperations', 'Bulk operations failed');
        }
    }

    /**
     * Runs the operations inside the open transaction. Any failure rolls back, zeroes the
     * counters and rethrows so the caller can report which operation failed.
     *
     * @param array<int, array<string, mixed>> $operations
     * @param array<string, mixed> $results Counters and errors, read by the caller after a throw
     */
    private function applyOperations(int $zoneId, array $operations, ?string $zoneType, ?string $zoneName, bool $useTransaction, array &$results, bool $dryRun = false): JsonResponse
    {
        $this->dryRunActive = $dryRun;
        $this->validateOnly = $dryRun && !$useTransaction;
        $this->batchCreated = [];
        $this->batchGoneIds = [];
        $this->batchFreedKeys = [];
        // Only non-SOA changes bump the serial, so a user-supplied SOA serial stays.
        $nonSOARecordModified = false;
        $this->reverseZoneIds = [];
        // An update matching the stored record writes nothing (bump_serial_on_unchanged_save off)
        $anyRecordWritten = false;
        try {
            foreach ($operations as $index => $operation) {
                $action = strtolower($operation['action'] ?? '');

                try {
                    $recordType = match ($action) {
                        'create' => $this->performCreateOperation($zoneId, $operation, $zoneType, $zoneName, "Operation $index (create)", $results['warnings']),
                        'update' => $this->performUpdateOperation($zoneId, $operation, $zoneType, $zoneName),
                        'delete' => $this->performDeleteOperation($zoneId, $operation, $zoneType, $zoneName),
                        default => throw new ApiErrorException("Invalid action: $action. Must be 'create', 'update', or 'delete'", 400),
                    };
                    $results[$action . 'd']++;
                    if ($recordType !== null) {
                        $anyRecordWritten = true;
                        $nonSOARecordModified = $nonSOARecordModified || $recordType !== 'SOA';
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
            $reverseZoneIds = array_keys(array_diff_key($this->reverseZoneIds, [$zoneId => true]));
            if (!$this->validateOnly) {
                if ($nonSOARecordModified) {
                    $this->services()->soaRecordManager()->updateSOASerial($zoneId);
                }
                // Reverse zones that got a PTR follow the same rule, once each.
                foreach ($reverseZoneIds as $reverseZoneId) {
                    $this->services()->soaRecordManager()->updateSOASerial($reverseZoneId);
                }
            }
            if ($dryRun) {
                // Rolled back on purpose, so the counters stay: they report what would have happened
                if ($useTransaction) {
                    $this->services()->transaction()->rollBack();
                }
                return $this->returnApiResponse($results, true, 'Bulk operations validated (dry run, nothing was written)', 200);
            }
            if ($useTransaction) {
                $this->services()->transaction()->commit();
            }
            if ($anyRecordWritten) {
                $this->recordManager->finalizeZone($zoneId, false);
            }
            foreach ($reverseZoneIds as $reverseZoneId) {
                $this->recordManager->finalizeZone($reverseZoneId, false);
            }

            $this->services()->auditService()->logApiBulkRecords($zoneId, $results['total_operations']);

            // Any failed operation rethrows above, so reaching here means all succeeded
            return $this->returnApiResponse($results, true, 'Bulk operations completed successfully', 200);
        } catch (\Throwable $e) {
            if ($useTransaction) {
                $this->services()->transaction()->rollBack();

                // Reset counters - rollback means no changes were persisted
                $results['created'] = 0;
                $results['updated'] = 0;
                $results['deleted'] = 0;
                $results['warnings'] = [];
            }

            throw $e;
        }
    }

    /**
     * Perform create operation
     *
     * @param int $zoneId Zone ID
     * @param array $operation Operation data
     * @param string $label Prefix for warnings, e.g. "Operation 2 (create)"
     * @param array<int, string> $warnings Collects non-fatal problems such as a failed PTR
     * @return string The record type that was created
     * @throws Exception If operation fails
     */
    private function performCreateOperation(int $zoneId, array $operation, ?string $zoneType = null, ?string $zoneName = null, string $label = 'Operation', array &$warnings = []): string
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
        // API backend: the comment rides in the RRset PATCH, as on a single POST.
        $comment = trim((string)$this->inputString($operation, 'comment', ''));
        $username = $this->getAuthenticatedUsername();
        $rrsetComment = $comment === '' ? null : ['content' => $comment, 'account' => $username];
        $created = $this->recordManager->addRecordGetId($zoneId, $normalizedName, $type, $content, $ttl, $priority, $disabled, false, $rrsetComment, $this->validateOnly);
        $batchKey = $this->batchKey($normalizedName, $type, $content);
        // A real run reads the zone after the batch's own delete or re-key, so that conflict is gone
        if ($this->validateOnly && !$created->success && $created->refusal === Refusal::CONFLICT && isset($this->batchFreedKeys[$batchKey])) {
            unset($this->batchFreedKeys[$batchKey]);
            $created = RecordWriteResult::ok();
        }
        if (!$created->success) {
            throw new ApiErrorException($this->recordWriteErrorMessage($created, 'Failed to create record'), RefusalStatus::of($created->refusal));
        }
        if ($this->validateOnly) {
            if (isset($this->batchCreated[$batchKey])) {
                throw new ApiErrorException(_('A record with this hostname, type, and content already exists.'), RefusalStatus::of(Refusal::CONFLICT));
            }
            $this->batchCreated[$batchKey] = true;
        }
        if ($comment !== '' && !$this->validateOnly) {
            $this->services()->recordCommentService()->createCommentForRecord($zoneId, $normalizedName, $type, $comment, $created->recordId, $username);
        }

        $isAddressType = $type === 'A' || $type === 'AAAA';
        if ($isAddressType && $this->inputBool($operation, 'create_ptr', false)) {
            $this->createCompanionPtr($zoneId, $normalizedName, $type, $content, $ttl, $priority, $comment, $label, $warnings);
        }
        // Whatever became of the PTR, the comment follows an existing counterpart, as on a single POST
        if ($comment !== '' && !$this->validateOnly) {
            $this->services()->recordManagerService()->syncCompanionComment($zoneId, $normalizedName, $type, $content, $comment, $username, $this->getAuthenticatedUserId());
        }

        return $type;
    }

    /**
     * The PTR of a created A/AAAA record. It goes through the creator the single POST uses; the
     * reverse zone's serial bump and rectify are left to the end of the batch. A failure is a
     * warning, not a failed operation, as on the single endpoint.
     *
     * @param array<int, string> $warnings
     */
    private function createCompanionPtr(int $zoneId, string $name, string $type, string $content, int $ttl, int $priority, string $comment, string $label, array &$warnings): void
    {
        $username = $this->getAuthenticatedUsername();
        $ptr = $this->services()->reverseRecordCreator()->createReverseRecord(
            $name,
            $type,
            $content,
            $zoneId,
            $this->reverseTtlResolver->resolvePtrTtl($ttl),
            $priority,
            $comment,
            $username,
            false,
            !$this->dryRunActive,
            $this->validateOnly
        );

        if (empty($ptr['success'])) {
            $warnings[] = "$label: PTR record creation failed: " . ($ptr['message'] ?? '');
            return;
        }
        if (isset($ptr['zone_id'])) {
            $this->reverseZoneIds[$ptr['zone_id']] = true;
        }
        if (($ptr['type'] ?? '') === 'warning') {
            $warnings[] = "$label: " . ($ptr['message'] ?? '');
        }
    }

    /**
     * Perform update operation
     *
     * @param int $zoneId Zone ID
     * @param array $operation Operation data
     * @return string|null The record type that was updated, or null when the record already matched and nothing was written
     * @throws Exception If operation fails
     */
    private function performUpdateOperation(int $zoneId, array $operation, ?string $zoneType = null, ?string $zoneName = null): ?string
    {
        if (!isset($operation['id'])) {
            throw new ApiErrorException("Field 'id' is required for update operation", 400);
        }

        $recordId = RecordIdHelper::normalizeId($operation['id']);

        // Get existing record
        $existingRecord = $this->recordRepository->getRecordById($recordId);
        if (!$existingRecord || isset($this->batchGoneIds[(string)$recordId]) || !$this->recordBelongsToZone($existingRecord, $zoneId, $zoneName)) {
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
        // Like the single PUT: a comment replaces the stored one, '' clears it, absent leaves it alone
        $comment = $this->inputString($operation, 'comment');
        if (
            $name === null || $type === null || $content === null || $ttl === null || $prio === null || $disabled === null
            || ($comment === null && array_key_exists('comment', $operation))
        ) {
            throw new ApiErrorException('Invalid field types in request body', 400);
        }
        // Trimmed as on create; whitespace-only becomes '' and clears the comment
        $comment = $comment === null ? null : trim($comment);
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

        $username = $this->getAuthenticatedUsername();
        $rrsetComment = $comment === null ? null : ['content' => $comment, 'account' => $username];
        $result = $this->recordManager->editRecord($recordData, false, $rrsetComment, $this->validateOnly);
        if (!$result->success) {
            // Backend faults keep the generic contract string; refusals carry their reason
            if ($result->refusal === Refusal::BACKEND_FAILURE) {
                throw new Exception('Failed to update record');
            }
            throw new ApiErrorException($result->refusal === Refusal::BACKEND_REJECTED ? $this->recordWriteErrorMessage($result, 'Failed to update record') : (string)$result->message, RefusalStatus::of($result->refusal));
        }
        if ($this->validateOnly) {
            $this->noteRekey($recordId, $existingRecord, $recordData);
        }
        if ($comment !== null && !$this->validateOnly) {
            $this->storeUpdatedComment($zoneId, $recordId, $recordData, $comment, $username);
        }

        return $result->changed ? $recordData['type'] : null;
    }

    /**
     * The API backend derives a record's id from its name, type, content and priority, so an
     * update that changes one retires the old id and frees the old name, type and content.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $submitted
     */
    private function noteRekey(int|string $recordId, array $stored, array $submitted): void
    {
        $oldKey = $this->batchKey((string)$stored['name'], (string)$stored['type'], (string)$stored['content']);
        $newKey = $this->batchKey((string)$submitted['name'], (string)$submitted['type'], (string)$submitted['content']);
        if ($oldKey === $newKey && (int)($stored['prio'] ?? 0) === (int)$submitted['prio']) {
            return;
        }
        $this->batchGoneIds[(string)$recordId] = true;
        if ($oldKey !== $newKey) {
            $this->batchFreedKeys[$oldKey] = true;
            $this->batchCreated[$newKey] = true;
        }
    }

    /** Name, type and content as the duplicate check compares them. */
    private function batchKey(string $name, string $type, string $content): string
    {
        $type = strtoupper($type);

        return strtolower($name) . "\0" . $type . "\0" . ApiDnsBackendProvider::stripTrailingDotFromContent($type, $content);
    }

    /**
     * Stores the comment against the saved record. The API backend re-keys a record when its
     * name, type, content or priority change, so the id is looked up again.
     *
     * @param array<string, mixed> $recordData The submitted record fields
     */
    private function storeUpdatedComment(int $zoneId, int|string $recordId, array $recordData, string $comment, string $username): void
    {
        $stored = $this->recordRepository->getRecordFromId($recordId);
        if ($stored !== null) {
            $savedId = $stored['id'] ?? $recordId;
        } else {
            $savedId = $this->recordRepository->getNewRecordId($zoneId, (string)$recordData['name'], (string)$recordData['type'], (string)$recordData['content']) ?? $recordId;
            $stored = $recordData;
        }

        $this->services()->recordCommentService()->updateCommentForRecord(
            $zoneId,
            (string)$stored['name'],
            (string)$stored['type'],
            $comment,
            RecordIdHelper::normalizeId($savedId),
            $username
        );
        // The same sync as a single PUT, so an empty comment clears the counterpart too
        if ($this->getConfig()->get('misc', 'record_comments_sync')) {
            $this->services()->recordCommentSyncService()->updateRelatedRecordComments(
                $this->services()->domainRepository(),
                $stored,
                $comment,
                $username,
                $this->getAuthenticatedUserId()
            );
        }
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
        if (!$existingRecord || isset($this->batchGoneIds[(string)$recordId]) || !$this->recordBelongsToZone($existingRecord, $zoneId, $zoneName)) {
            throw new ApiErrorException("Record not found in this zone", 404);
        }

        // The stored type decides which records a client-limited caller may touch
        $recordType = $existingRecord['type'];

        // Block SOA/NS deletes for users limited to zone_content_edit_own_as_client
        if (!$this->apiPermissionService->canEditZoneRecord($this->getAuthenticatedUserId(), $zoneId, (string)$recordType, $zoneType, (string)$existingRecord['name'], $zoneName)) {
            throw new ApiErrorException('You do not have permission to delete this record type', 403);
        }

        $result = $this->recordManager->deleteRecord($recordId, false, $this->validateOnly);
        if (!$result->success) {
            // Backend faults keep the generic contract string; refusals carry their reason
            if ($result->refusal === Refusal::BACKEND_FAILURE) {
                throw new Exception('Failed to delete record');
            }
            throw new ApiErrorException($result->refusal === Refusal::BACKEND_REJECTED ? $this->recordWriteErrorMessage($result, 'Failed to delete record') : (string)$result->message, RefusalStatus::of($result->refusal));
        }
        if ($this->validateOnly) {
            $this->batchGoneIds[(string)$recordId] = true;
            $this->batchFreedKeys[$this->batchKey((string)$existingRecord['name'], (string)$recordType, (string)$existingRecord['content'])] = true;
        }

        return $recordType;
    }
}
