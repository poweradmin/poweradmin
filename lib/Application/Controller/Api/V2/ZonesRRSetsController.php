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

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Controller\Api\V2\Resource\RecordResource;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RRSetReplaceService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Repository\RecordListingInterface;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * /api/v2/zones/{id}/rrsets: lists, replaces and deletes records grouped by name and type.
 */
class ZonesRRSetsController extends PublicApiController
{
    private ZoneReadRepositoryInterface $zoneRepository;
    private RecordListingInterface $recordRepository;
    private RecordManagerInterface $recordManager;
    private ApiPermissionService $apiPermissionService;
    private ReverseTtlResolver $reverseTtlResolver;
    private RRSetReplaceService $rrsetReplaceService;
    private BackendCapabilitiesInterface $backendProvider;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->backendProvider = $this->services()->dnsBackendProvider();
        $this->reverseTtlResolver = $this->services()->reverseTtlResolver();
        $this->zoneRepository = $this->services()->zoneRepository();
        $this->recordRepository = $this->services()->recordRepository();
        $this->apiPermissionService = $this->services()->apiPermissionService();

        $this->recordManager = $this->services()->recordManager();
        $this->rrsetReplaceService = $this->services()->rrsetReplaceService();
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
            default => $this->methodNotAllowed(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
        };

        $response->send();
        exit;
    }

    // POST/PUT/PATCH all replace an RRSet (an upsert that may create or update),
    // so writes require both operations rather than the method's default mapping.
    protected function requiredApiKeyOperations(): array
    {
        return match (strtoupper($this->request->getMethod())) {
            'GET', 'HEAD' => [ApiKeyScope::OP_VIEW],
            'DELETE' => [ApiKeyScope::OP_DELETE],
            default => [ApiKeyScope::OP_CREATE, ApiKeyScope::OP_UPDATE],
        };
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

            // Group records into RRSets (by name + type)
            $rrsets = $this->groupIntoRRSets($records);

            return $this->returnApiResponse(['rrsets' => $rrsets], true, 'RRSets retrieved successfully', 200);
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
                        new OA\Property(
                            property: 'rrset',
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
                    ],
                    type: 'object'
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

            // Get zone name for FQDN construction
            $zoneName = $this->services()->domainRepository()->getDomainNameById($zoneId);

            // Convert name to FQDN
            $fqdn = $this->normalizeV2RecordName($name, $zoneName);

            // Get all records matching this name and type
            $records = $this->recordRepository->getRRSetRecords($zoneId, $fqdn, $type);

            if (empty($records)) {
                return $this->returnApiError('RRSet not found', 404);
            }

            // Format as RRSet
            $rrset = $this->formatRRSet($records, $zoneName);

            return $this->returnApiResponse(['rrset' => $rrset], true, 'RRSet retrieved successfully', 200);
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
    #[OA\Post(
        path: '/v2/zones/{id}/rrsets',
        operationId: 'v2CreateZoneRRSet',
        summary: 'Replace or create an RRSet',
        description: 'Alias of PUT on this path.',
        tags: ['rrsets'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]]
    )]
    #[OA\Patch(
        path: '/v2/zones/{id}/rrsets',
        operationId: 'v2PatchZoneRRSet',
        summary: 'Replace or create an RRSet',
        description: 'Alias of PUT on this path.',
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
                        new OA\Property(
                            property: 'records',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'content', type: 'string', example: '192.168.1.1'),
                                    new OA\Property(property: 'priority', type: 'integer', example: 0),
                                    new OA\Property(property: 'disabled', type: 'boolean', example: false)
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
    private function replaceRRSet(): JsonResponse
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

            if (($approval = $this->refuseWhenChangeRequestRequired($this->apiPermissionService, $userId, $zoneId)) !== null) {
                return $approval;
            }

            $input = $this->getValidatedJsonBody();
            if ($input === null) {
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

            $nameRaw = $this->inputString($input, 'name', '');
            $typeRaw = $this->inputString($input, 'type', '');
            if ($nameRaw === null || $typeRaw === null) {
                return $this->returnApiError('Invalid field types in request body', 400);
            }
            $name = trim($nameRaw);
            $type = strtoupper(trim($typeRaw));

            // Get zone name
            $zoneName = $this->services()->domainRepository()->getDomainNameById($zoneId);
            if ($zoneName === null) {
                return $this->returnApiError('Zone not found', 404);
            }
            $isReverseZone = DnsHelper::isReverseZoneName($zoneName);

            $ttl = $this->inputInt($input, 'ttl', $this->reverseTtlResolver->resolveTtlForType($type, $isReverseZone));
            if ($ttl === null) {
                return $this->returnApiError('Invalid field types in request body', 400);
            }

            // Validate TTL
            // 0 is RFC-valid ("do not cache") and TTLValidator accepts it
            if ($ttl < 0) {
                return $this->returnApiError('TTL must not be negative', 400);
            }

            // Convert name to FQDN
            $fqdn = $this->normalizeV2RecordName($name, $zoneName);

            // Block SOA/NS RRSet edits for users limited to zone_content_edit_own_as_client;
            // checked after FQDN conversion so the subzone NS exemption sees the full name
            if (!$this->apiPermissionService->canEditZoneRecord($userId, $zoneId, $type, $zone['type'] ?? null, $fqdn, $zoneName)) {
                return $this->returnApiError('You do not have permission to edit this record type', 403);
            }

            // Parsed up front: on the API backend there is no transaction, so a bad
            // 'disabled' or 'priority' must be refused before the old set is gone (audit H5).
            $records = [];
            foreach ($input['records'] as $recordData) {
                if (!isset($recordData['content'])) {
                    continue;
                }

                $disabled = $this->inputIntFromBool($recordData, 'disabled', 0);
                $priority = $this->inputInt($recordData, 'priority', 0);
                if ($disabled === null || $priority === null) {
                    return $this->returnApiError("Invalid 'disabled' or 'priority' value in record", 400);
                }

                $records[] = [
                    'content' => $this->formatV2RecordContent($type, trim($recordData['content'])),
                    'priority' => $priority,
                    'disabled' => $disabled,
                ];
            }

            $result = $this->rrsetReplaceService->replace($zoneId, $zoneName, $fqdn, $type, $ttl, $records);
            if (!$result['success']) {
                $message = isset($result['write'])
                    ? $this->recordWriteErrorMessage($result['write'], 'Failed to insert record: ' . $result['content'])
                    : $result['message'];
                return $this->returnApiError($message, $result['status']);
            }
            $normalizedName = $result['name'];
            $validatedRecords = $result['records'];

            // Fetch and return the full RRSet (outside transaction block so readback
            // failures don't trigger rollback or mask the successful write)
            try {
                $rrsetRecords = $this->recordRepository->getRRSetRecords($zoneId, $fqdn, $type);
                $rrset = $this->formatRRSet($rrsetRecords, $zoneName);
                return $this->returnApiResponse(['rrset' => $rrset], true, 'RRSet replaced successfully', 200);
            } catch (\Throwable $e) {
                // Readback failed but write succeeded - reconstruct from validated input
                // using the same transformations as formatRRSet()
                $fallbackRecords = array_map(fn(array $vr): array => RecordResource::rrsetMember([
                    'type' => $type,
                    'content' => $vr['content'],
                    'prio' => $vr['priority'],
                    'disabled' => $vr['disabled'],
                ]), $validatedRecords);

                return $this->returnApiResponse(
                    ['rrset' => [
                        'name' => DnsHelper::stripZoneSuffix($normalizedName, $zoneName),
                        'type' => $type,
                        'ttl' => $ttl,
                        'records' => $fallbackRecords,
                    ]],
                    true,
                    'RRSet replaced successfully',
                    200
                );
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
        description: 'RRSet deleted successfully'
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

            if (($approval = $this->refuseWhenChangeRequestRequired($this->apiPermissionService, $userId, $zoneId)) !== null) {
                return $approval;
            }

            // Get zone name
            $zoneName = $this->services()->domainRepository()->getDomainNameById($zoneId);

            // Convert name to FQDN
            $fqdn = $this->normalizeV2RecordName($name, $zoneName);

            // Block SOA/NS RRSet deletes for users limited to zone_content_edit_own_as_client;
            // checked after FQDN conversion so the subzone NS exemption sees the full name
            if (!$this->apiPermissionService->canEditZoneRecord($userId, $zoneId, $type, $zone['type'] ?? null, $fqdn, $zoneName)) {
                return $this->returnApiError('You do not have permission to delete this record type', 403);
            }

            // Get all records matching this name and type
            $records = $this->recordRepository->getRRSetRecords($zoneId, $fqdn, $type);

            if (empty($records)) {
                return $this->returnApiError('RRSet not found', 404);
            }

            // On the API backend the deletes are already sent by the time a local
            // transaction could roll back, so it is only opened where it can undo them
            $useTransaction = $this->backendProvider->supportsLocalWriteTransaction();
            if ($useTransaction) {
                $this->db->beginTransaction();
            }

            try {
                $recordsDeleted = 0;
                $totalRecords = count($records);

                foreach ($records as $record) {
                    if (!$this->recordManager->deleteRecord($record['id'], false)->success) {
                        if ($useTransaction) {
                            $this->db->rollBack();
                        }
                        return $this->returnApiError(
                            'Failed to delete record with ID ' . $record['id'] . ' (name: ' . $record['name'] . ', type: ' . $type . ')',
                            500
                        );
                    }
                    $recordsDeleted++;
                }

                // Verify all records were deleted
                if ($recordsDeleted !== $totalRecords) {
                    if ($useTransaction) {
                        $this->db->rollBack();
                    }
                    return $this->returnApiError(
                        'RRSet deletion incomplete: deleted ' . $recordsDeleted . ' of ' . $totalRecords . ' records',
                        500
                    );
                }

                if ($type !== 'SOA') {
                    $this->services()->soaRecordManager()->updateSOASerial($zoneId);
                }
                if ($useTransaction) {
                    $this->db->commit();
                }
                $this->recordManager->finalizeZone($zoneId, false);

                $this->services()->auditService()->logApiRrsetDelete($zoneId, $fqdn, $type, $recordsDeleted);

                return $this->returnApiResponse(
                    ['records_deleted' => $recordsDeleted],
                    true,
                    'RRSet deleted successfully',
                    204
                );
            } catch (\Throwable $e) {
                if ($useTransaction) {
                    $this->db->rollBack();
                }
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
            // Skip ENT (Empty Non-Terminal) records created by PowerDNS for RFC 8020 compliance
            if (empty($record['type']) || empty($record['name'])) {
                continue;
            }

            $key = $record['name'] . '|' . $record['type'];

            if (!isset($rrsets[$key])) {
                $rrsets[$key] = [
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'ttl' => (int)$record['ttl'],
                    'records' => []
                ];
            }

            $rrsets[$key]['records'][] = RecordResource::rrsetMember($record);

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
        // Filter out ENT (Empty Non-Terminal) records created by PowerDNS for RFC 8020 compliance.
        // array_values() so dropped rows cannot leave gaps in the keys, which would
        // serialize the records list as a JSON object instead of an array.
        $validRecords = array_values(array_filter($records, function ($record) {
            return !empty($record['type']) && !empty($record['name']);
        }));

        if (empty($validRecords)) {
            return [];
        }

        $firstRecord = reset($validRecords);

        return [
            'name' => DnsHelper::stripZoneSuffix($firstRecord['name'], $zoneName),
            'type' => $firstRecord['type'],
            'ttl' => (int)$firstRecord['ttl'],
            'records' => array_map(RecordResource::rrsetMember(...), $validRecords)
        ];
    }
}
