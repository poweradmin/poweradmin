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
use PDO;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
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
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZonesRecordsBulkController extends PublicApiController
{
    private DbZoneRepository $zoneRepository;
    private RecordRepository $recordRepository;
    private RecordManagerInterface $recordManager;
    private SOARecordManager $soaRecordManager;
    private TableNameService $tableNameService;
    private ApiPermissionService $permissionService;
    private RecordCommentService $recordCommentService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $this->recordRepository = new RecordRepository($this->db, $this->getConfig());
        $this->tableNameService = new TableNameService($this->getConfig());
        $this->permissionService = new ApiPermissionService($this->db);

        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $this->recordCommentService = new RecordCommentService($recordCommentRepository);

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

    /**
     * Perform bulk record operations (create, update, delete)
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/v2/zones/{id}/records/bulk',
        operationId: 'v2BulkRecordOperations',
        summary: 'Perform bulk record operations',
        description: 'Create, update, or delete multiple records in a single atomic transaction. All operations succeed or fail together.',
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
                            new OA\Property(property: 'id', type: 'integer', example: 123, description: 'Record ID (required for update/delete)'),
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
            if (!$input || !isset($input['operations']) || !is_array($input['operations'])) {
                return $this->returnApiError("Field 'operations' is required and must be an array", 400);
            }

            if (empty($input['operations'])) {
                return $this->returnApiError("At least one operation is required", 400);
            }

            // Start transaction for atomic operations
            $this->db->beginTransaction();

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
                                $recordType = $this->performCreateOperation($zoneId, $operation);
                                $results['created']++;
                                if ($recordType !== 'SOA') {
                                    $nonSOARecordModified = true;
                                }
                                break;

                            case 'update':
                                $recordType = $this->performUpdateOperation($zoneId, $operation);
                                $results['updated']++;
                                if ($recordType !== 'SOA') {
                                    $nonSOARecordModified = true;
                                }
                                break;

                            case 'delete':
                                $recordType = $this->performDeleteOperation($zoneId, $operation);
                                $results['deleted']++;
                                if ($recordType !== 'SOA') {
                                    $nonSOARecordModified = true;
                                }
                                break;

                            default:
                                throw new ApiErrorException("Invalid action: $action. Must be 'create', 'update', or 'delete'", 400);
                        }
                    } catch (Exception $e) {
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

                $this->db->commit();

                $message = 'Bulk operations completed successfully';
                if ($results['failed'] > 0) {
                    $message = 'Some operations failed';
                }

                return $this->returnApiResponse($results, true, $message, 200);
            } catch (Exception $e) {
                $this->db->rollBack();

                // Reset counters on rollback - no changes were actually persisted
                $results['created'] = 0;
                $results['updated'] = 0;
                $results['deleted'] = 0;

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
        } catch (Exception $e) {
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
    private function performCreateOperation(int $zoneId, array $operation): string
    {
        // Validate required fields
        $requiredFields = ['name', 'type', 'content'];
        foreach ($requiredFields as $field) {
            if (!isset($operation[$field]) || trim($operation[$field]) === '') {
                throw new ApiErrorException("Field '$field' is required for create operation", 400);
            }
        }

        $name = trim($operation['name']);
        $type = strtoupper(trim($operation['type']));
        $content = trim($operation['content']);
        $ttl = isset($operation['ttl']) ? (int)$operation['ttl'] : 3600;
        $priority = isset($operation['priority']) ? (int)$operation['priority'] : 0;
        $disabled = isset($operation['disabled']) ? (int)$operation['disabled'] : 0;

        // Validate TTL
        if ($ttl < 1) {
            throw new ApiErrorException('TTL must be greater than 0', 400);
        }

        // Get zone name
        $domainRepository = new DomainRepository($this->db, $this->getConfig());
        $zoneName = $domainRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            throw new ApiErrorException('Zone not found', 404);
        }

        // Convert name to FQDN
        $fqdn = DnsHelper::restoreZoneSuffix($name, $zoneName);

        // Normalize the hostname
        $hostnameValidator = new HostnameValidator($this->getConfig());
        $normalizedName = $hostnameValidator->normalizeRecordName($fqdn, $zoneName);

        // Format content
        $dnsFormatter = new DnsFormatter($this->getConfig());
        $content = $dnsFormatter->formatContent($type, $content);

        // Auto-quote TXT records (V2 API convention)
        if ($type === 'TXT') {
            $content = trim($content);
            if (!str_starts_with($content, '"') || !str_ends_with($content, '"')) {
                $content = '"' . $content . '"';
            }
        }

        // Validate the record
        $validationService = DnsServiceFactory::createDnsRecordValidationService($this->db, $this->getConfig());
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

        // Insert record (database error, not validation error)
        if (!$this->insertRecordDirect($zoneId, $normalizedName, $type, $validatedContent, $validatedTtl, $validatedPriority, $disabled)) {
            throw new Exception('Failed to create record');
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
    private function performUpdateOperation(int $zoneId, array $operation): string
    {
        if (!isset($operation['id'])) {
            throw new ApiErrorException("Field 'id' is required for update operation", 400);
        }

        $recordId = (int)$operation['id'];

        // Get existing record
        $existingRecord = $this->recordRepository->getRecordById($recordId);
        if (!$existingRecord || $existingRecord['domain_id'] != $zoneId) {
            throw new ApiErrorException("Record not found in this zone", 404);
        }

        // Prepare record data for update
        $recordData = [
            'rid' => $recordId,
            'zid' => $zoneId,
            'name' => $operation['name'] ?? $existingRecord['name'],
            'type' => isset($operation['type']) ? strtoupper(trim($operation['type'])) : $existingRecord['type'],
            'content' => $operation['content'] ?? $existingRecord['content'],
            'ttl' => isset($operation['ttl']) ? (int)$operation['ttl'] : (int)$existingRecord['ttl'],
            'prio' => isset($operation['priority']) ? (int)$operation['priority'] : (int)($existingRecord['prio'] ?? 0),
            'disabled' => isset($operation['disabled']) ? (int)$operation['disabled'] : (int)($existingRecord['disabled'] ?? 0)
        ];

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
    private function performDeleteOperation(int $zoneId, array $operation): string
    {
        if (!isset($operation['id'])) {
            throw new ApiErrorException("Field 'id' is required for delete operation", 400);
        }

        $recordId = (int)$operation['id'];

        // Verify record exists in this zone
        $existingRecord = $this->recordRepository->getRecordById($recordId);
        if (!$existingRecord || $existingRecord['domain_id'] != $zoneId) {
            throw new ApiErrorException("Record not found in this zone", 404);
        }

        // Store record type before deletion (for SOA serial update logic)
        $recordType = $existingRecord['type'];

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
        } catch (Exception $e) {
            error_log('Failed to insert record: ' . $e->getMessage());
            return false;
        }
    }
}
