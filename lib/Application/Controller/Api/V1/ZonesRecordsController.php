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

namespace Poweradmin\Application\Controller\Api\V1;

use Exception;
use PDO;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

class ZonesRecordsController extends PublicApiController
{
    private DbZoneRepository $zoneRepository;
    private RecordRepository $recordRepository;
    private RecordManagerInterface $recordManager;
    private TableNameService $tableNameService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $this->recordRepository = new RecordRepository($this->db, $this->getConfig());
        $this->tableNameService = new TableNameService($this->getConfig());

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
     * Handle zone records requests
     */
    #[\Override]
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['sub_id']) ? $this->getRecord() : $this->listRecords(),
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
                            new OA\Property(property: 'priority', type: 'integer', example: 10)
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
            $zoneId = $this->pathParameters['id'];
            $recordType = $this->request->query->get('type');

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Get records for the zone
            $records = $this->recordRepository->getRecordsByDomainId($zoneId, $recordType);

            // Format record data
            $formattedRecords = array_map(function ($record) {
                return [
                    'id' => (int)$record['id'],
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'content' => $record['content'],
                    'ttl' => (int)$record['ttl'],
                    'priority' => isset($record['prio']) ? (int)$record['prio'] : null
                ];
            }, $records);

            return $this->returnApiResponse($formattedRecords, true, 'Records retrieved successfully', 200, [
                'zone_id' => $zoneId,
                'zone_name' => $zone['name'],
                'meta' => [
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
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
        description: 'Record retrieved successfully'
    )]
    #[OA\Response(
        response: 404,
        description: 'Record not found'
    )]
    private function getRecord(): JsonResponse
    {
        try {
            $zoneId = $this->pathParameters['id'];
            $recordId = $this->pathParameters['sub_id'];

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
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
                'priority' => isset($record['prio']) ? (int)$record['prio'] : null
            ];

            return $this->returnApiResponse($formattedRecord, true, 'Record retrieved successfully', 200, [
                'zone_id' => $zoneId,
                'zone_name' => $zone['name'],
                'meta' => [
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
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
                new OA\Property(property: 'priority', type: 'integer', example: 10, description: 'Priority (for MX, SRV records, etc.)')
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
                        new OA\Property(property: 'content', type: 'string', example: '192.168.1.1')
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
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            if ($zoneId <= 0) {
                return $this->returnApiError('Valid zone ID is required', 400);
            }

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
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
            $content = trim($input['content']);
            $ttl = isset($input['ttl']) ? (int)$input['ttl'] : 3600;
            $priority = isset($input['priority']) ? (int)$input['priority'] : 0;

            // Validate TTL
            if ($ttl < 1) {
                return $this->returnApiError('TTL must be greater than 0', 400);
            }

            // For API usage, bypass permission checks and insert directly
            $success = $this->createRecordDirect($zoneId, $name, $type, $content, $ttl, $priority);

            if (!$success) {
                return $this->returnApiError('Failed to create record', 500);
            }

            // Get the newly created record ID (we'll need to query for it since addRecord doesn't return ID)
            $records = $this->recordRepository->getRecordsByDomainId($zoneId);
            $newRecord = null;
            foreach ($records as $record) {
                if ($record['name'] === $name && $record['type'] === $type && $record['content'] === $content) {
                    $newRecord = $record;
                    break;
                }
            }

            $responseData = [
                'record_id' => $newRecord ? (int)$newRecord['id'] : null,
                'name' => $name,
                'type' => $type,
                'content' => $content,
                'ttl' => $ttl,
                'priority' => $priority
            ];

            return $this->returnApiResponse($responseData, true, 'Record created successfully', 201, [
                'zone_id' => $zoneId,
                'zone_name' => $zone['name'],
                'meta' => [
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
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
        path: '/v1/zones/{id}/records/{recordId}',
        operationId: 'v1UpdateZoneRecord',
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
                new OA\Property(property: 'priority', type: 'integer', example: 10)
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
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            $recordId = (int)($this->pathParameters['sub_id'] ?? 0);

            if ($zoneId <= 0 || $recordId <= 0) {
                return $this->returnApiError('Valid zone ID and record ID are required', 400);
            }

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
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
                'prio' => isset($input['priority']) ? (int)$input['priority'] : (int)($existingRecord['prio'] ?? 0)
            ];

            // Validate TTL
            if ($recordData['ttl'] < 1) {
                return $this->returnApiError('TTL must be greater than 0', 400);
            }

            // Use RecordManager to edit the record
            $success = $this->recordManager->editRecord($recordData);

            if (!$success) {
                return $this->returnApiError('Failed to update record', 500);
            }

            return $this->returnApiResponse(null, true, 'Record updated successfully', 200, [
                'zone_id' => $zoneId,
                'record_id' => $recordId,
                'zone_name' => $zone['name'],
                'meta' => [
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
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
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            $recordId = (int)($this->pathParameters['sub_id'] ?? 0);

            if ($zoneId <= 0 || $recordId <= 0) {
                return $this->returnApiError('Valid zone ID and record ID are required', 400);
            }

            // Verify zone exists
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Verify record exists in this zone
            $existingRecord = $this->recordRepository->getRecordById($recordId);
            if (!$existingRecord || $existingRecord['domain_id'] != $zoneId) {
                return $this->returnApiError('Record not found in this zone', 404);
            }

            // Use RecordManager to delete the record
            $success = $this->recordManager->deleteRecord($recordId);

            if (!$success) {
                return $this->returnApiError('Failed to delete record', 500);
            }

            return $this->returnApiResponse(null, true, 'Record deleted successfully', 204, [
                'zone_id' => $zoneId,
                'record_id' => $recordId,
                'zone_name' => $zone['name'],
                'meta' => [
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to delete record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a record directly in the database (bypassing permission checks for API usage)
     *
     * @param int $zoneId Zone ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param int $ttl TTL value
     * @param int $priority Priority value
     * @return bool True if successful
     */
    private function createRecordDirect(int $zoneId, string $name, string $type, string $content, int $ttl, int $priority): bool
    {
        try {
            $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

            // Basic record validation
            if (empty($name) || empty($type) || empty($content)) {
                return false;
            }

            // Start transaction
            $this->db->beginTransaction();

            // Insert the record
            $query = "INSERT INTO $records_table (domain_id, name, type, content, ttl, prio) 
                      VALUES (:zone_id, :name, :type, :content, :ttl, :prio)";

            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->bindValue(':content', $content, PDO::PARAM_STR);
            $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
            $stmt->bindValue(':prio', $priority, PDO::PARAM_INT);

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
            error_log('Failed to create record: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update SOA serial for a zone
     *
     * @param int $zoneId Zone ID
     * @return void
     */
    private function updateSOASerial(int $zoneId): void
    {
        try {
            $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

            // Get current SOA record
            $query = "SELECT content FROM $records_table WHERE domain_id = :zone_id AND type = 'SOA'";
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();

            $soaContent = $stmt->fetchColumn();
            if (!$soaContent) {
                return;
            }

            // Parse SOA content and update serial
            $parts = explode(' ', $soaContent);
            if (count($parts) >= 3) {
                $currentSerial = $parts[2];
                $today = date('Ymd');

                // Generate new serial
                if (substr($currentSerial, 0, 8) === $today) {
                    // Same day, increment the sequence number
                    $sequence = intval(substr($currentSerial, 8)) + 1;
                    $newSerial = $today . str_pad((string)$sequence, 2, '0', STR_PAD_LEFT);
                } else {
                    // New day, start with 01
                    $newSerial = $today . '01';
                }

                $parts[2] = $newSerial;
                $newSoaContent = implode(' ', $parts);

                // Update SOA record
                $updateQuery = "UPDATE $records_table SET content = :content WHERE domain_id = :zone_id AND type = 'SOA'";
                $updateStmt = $this->db->prepare($updateQuery);
                $updateStmt->bindValue(':content', $newSoaContent, PDO::PARAM_STR);
                $updateStmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
                $updateStmt->execute();
            }
        } catch (Exception $e) {
            error_log('Failed to update SOA serial: ' . $e->getMessage());
        }
    }
}
