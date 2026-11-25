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
 * RESTful API v2 controller for zone metadata operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

class ZoneMetadataController extends PublicApiController
{
    private ZoneRepositoryInterface $zoneRepository;
    private ApiPermissionService $apiPermissionService;
    private ?PowerdnsApiClient $apiClient = null;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = $this->createZoneRepository();
        $this->apiPermissionService = new ApiPermissionService($this->db);
        if (DnsBackendProviderFactory::isApiBackend($this->config)) {
            $this->apiClient = DnsBackendProviderFactory::createApiClient($this->config, $this->logger);
        }
    }

    public function run(): void
    {
        $method = $this->request->getMethod();
        $hasKind = !empty($this->pathParameters['kind']);

        $response = match (true) {
            $method === 'GET' && !$hasKind => $this->listMetadata(),
            $method === 'GET' && $hasKind => $this->getMetadataKind(),
            $method === 'PUT' && $hasKind => $this->updateMetadataKind(),
            $method === 'DELETE' && $hasKind => $this->deleteMetadataKind(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    #[OA\Get(
        path: '/v2/zones/{id}/metadata',
        operationId: 'v2ListZoneMetadata',
        description: 'Retrieves all metadata for a zone',
        summary: 'List zone metadata',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Zone ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Metadata retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Metadata retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(
                            property: 'metadata',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'kind', type: 'string', example: 'ALLOW-AXFR-FROM'),
                                    new OA\Property(
                                        property: 'values',
                                        type: 'array',
                                        items: new OA\Items(type: 'string'),
                                        example: ['192.0.2.10', 'AUTO-NS']
                                    ),
                                ],
                                type: 'object'
                            )
                        )
                    ],
                    type: 'object'
                )
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone not found')]
    private function listMetadata(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canViewZone($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to view this zone', 403);
        }

        try {
            $rows = $this->loadMetadata($zoneId);
            $grouped = $this->groupMetadataByKind($rows);

            return $this->returnApiResponse(['metadata' => $grouped], true, 'Metadata retrieved successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/v2/zones/{id}/metadata/{kind}',
        operationId: 'v2GetZoneMetadataKind',
        description: 'Retrieves metadata for a specific kind',
        summary: 'Get zone metadata by kind',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Zone ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'kind',
                description: 'Metadata kind (e.g., ALLOW-AXFR-FROM)',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Metadata retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Metadata retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'kind', type: 'string', example: 'ALLOW-AXFR-FROM'),
                        new OA\Property(
                            property: 'values',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            example: ['192.0.2.10', 'AUTO-NS']
                        ),
                    ],
                    type: 'object'
                )
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone or metadata kind not found')]
    private function getMetadataKind(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];
        $kind = strtoupper($this->pathParameters['kind']);

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canViewZone($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to view this zone', 403);
        }

        try {
            $rows = $this->loadMetadata($zoneId);
            $values = [];
            foreach ($rows as $row) {
                if (strtoupper($row['kind']) === $kind) {
                    $values[] = $row['content'];
                }
            }

            if (empty($values)) {
                return $this->returnApiError('Metadata kind not found', 404);
            }

            return $this->returnApiResponse(
                ['kind' => $kind, 'values' => $values],
                true,
                'Metadata retrieved successfully'
            );
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    #[OA\Put(
        path: '/v2/zones/{id}/metadata/{kind}',
        operationId: 'v2UpdateZoneMetadataKind',
        description: 'Creates or replaces all values for a metadata kind',
        summary: 'Set zone metadata kind',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'values',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        example: ['192.0.2.10', 'AUTO-NS']
                    ),
                ]
            )
        ),
        tags: ['zones'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Zone ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'kind',
                description: 'Metadata kind (e.g., ALLOW-AXFR-FROM)',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Metadata updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Metadata updated successfully'),
                new OA\Property(property: 'data', type: 'object', nullable: true)
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden or read-only metadata kind')]
    #[OA\Response(response: 404, description: 'Zone not found')]
    private function updateMetadataKind(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];
        $kind = strtoupper($this->pathParameters['kind']);

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canEditZoneMeta($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to edit zone metadata', 403);
        }

        if (!MetadataDefinitions::isValidKindShape($kind)) {
            return $this->returnApiError('Invalid metadata kind', 400);
        }

        if (
            MetadataDefinitions::isOperatorOnly($kind)
            && !$this->apiPermissionService->userHasPermission($this->authenticatedUserId, 'user_is_ueberuser')
        ) {
            return $this->returnApiError('Metadata kind ' . $kind . ' can only be set by an administrator', 403);
        }

        if (!MetadataDefinitions::isApiWritable($kind)) {
            return $this->returnApiError('Metadata kind ' . $kind . ' is read-only', 403);
        }

        if (
            MetadataDefinitions::isDnssecState($kind)
            && !$this->apiPermissionService->canEditZone($this->authenticatedUserId, $zoneId)
        ) {
            return $this->returnApiError('You do not have permission to change DNSSEC metadata kind ' . $kind, 403);
        }

        try {
            $data = json_decode($this->request->getContent(), true);

            if (!isset($data['values']) || !is_array($data['values'])) {
                return $this->returnApiError('Missing required field: values (array)', 400);
            }

            $values = array_map('strval', $data['values']);

            if (empty($values)) {
                return $this->returnApiError('Values array must not be empty. Use DELETE to remove metadata.', 400);
            }

            // Checked before the limits, which load stored metadata (a PowerDNS call in API backend mode)
            if (!MetadataDefinitions::isMultiValue($kind) && count($values) > 1) {
                return $this->returnApiError('Metadata kind ' . $kind . ' accepts only a single value', 400);
            }

            if (count($values) > MetadataDefinitions::MAX_VALUES_PER_KIND && count($values) > $this->storedValueCount($zoneId, $kind)) {
                return $this->returnApiError('Metadata kind ' . $kind . ' accepts at most ' . MetadataDefinitions::MAX_VALUES_PER_KIND . ' values', 400);
            }

            $limitError = $this->metadataLimitError($zoneId, $kind, $values);
            if ($limitError !== null) {
                return $this->returnApiError($limitError, 400);
            }

            if (!$this->saveMetadataKind($zoneId, $kind, $values)) {
                return $this->returnApiError('Failed to update metadata', 500);
            }

            return $this->returnApiResponse(null, true, 'Metadata updated successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    #[OA\Delete(
        path: '/v2/zones/{id}/metadata/{kind}',
        operationId: 'v2DeleteZoneMetadataKind',
        description: 'Deletes all values for a metadata kind',
        summary: 'Delete zone metadata kind',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Zone ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'kind',
                description: 'Metadata kind (e.g., ALLOW-AXFR-FROM)',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Metadata deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Metadata deleted successfully'),
                new OA\Property(property: 'data', type: 'object', nullable: true)
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden or read-only metadata kind')]
    #[OA\Response(response: 404, description: 'Zone not found')]
    private function deleteMetadataKind(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];
        $kind = strtoupper($this->pathParameters['kind']);

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canEditZoneMeta($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to edit zone metadata', 403);
        }

        if (!MetadataDefinitions::isValidKindShape($kind)) {
            return $this->returnApiError('Invalid metadata kind', 400);
        }

        if (
            MetadataDefinitions::isOperatorOnly($kind)
            && !$this->apiPermissionService->userHasPermission($this->authenticatedUserId, 'user_is_ueberuser')
        ) {
            return $this->returnApiError('Metadata kind ' . $kind . ' can only be set by an administrator', 403);
        }

        if (!MetadataDefinitions::isApiWritable($kind)) {
            return $this->returnApiError('Metadata kind ' . $kind . ' is read-only', 403);
        }

        if (
            MetadataDefinitions::isDnssecState($kind)
            && !$this->apiPermissionService->canEditZone($this->authenticatedUserId, $zoneId)
        ) {
            return $this->returnApiError('You do not have permission to change DNSSEC metadata kind ' . $kind, 403);
        }

        try {
            if (!$this->deleteMetadataKindStorage($zoneId, $kind)) {
                return $this->returnApiError('Failed to delete metadata', 500);
            }

            return $this->returnApiResponse(null, true, 'Metadata deleted successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Load all metadata rows for a zone.
     *
     * @return array<int, array{kind: string, content: string}>
     */
    private function loadMetadata(int $zoneId): array
    {
        if ($this->apiClient !== null) {
            $zone = $this->zoneRepository->getZone($zoneId);
            if ($zone === null) {
                return [];
            }
            $zoneObj = new Zone($zone['name']);
            $apiMetadata = $this->apiClient->getZoneMetadata($zoneObj);
            $rows = [];
            foreach ($apiMetadata as $entry) {
                $kind = $entry['kind'] ?? '';
                foreach (($entry['metadata'] ?? []) as $value) {
                    $rows[] = ['kind' => $kind, 'content' => (string)$value];
                }
            }

            // SOA-EDIT-API is stored on the zone object, not in /metadata
            $zoneData = $this->apiClient->getZone($zone['name']);
            if ($zoneData !== null && !empty($zoneData['soa_edit_api'])) {
                $rows[] = ['kind' => 'SOA-EDIT-API', 'content' => $zoneData['soa_edit_api']];
            }

            return $rows;
        }

        return $this->zoneRepository->getDomainMetadata($zoneId);
    }

    /**
     * Refuses a value over the length cap, or a new kind on a zone at the kind cap. What is
     * already stored may be kept or shrunk, never grown.
     *
     * @param array<int, string> $values
     */
    private function metadataLimitError(int $zoneId, string $kind, array $values): ?string
    {
        $tooLong = array_filter($values, static fn(string $v): bool => strlen($v) > MetadataDefinitions::MAX_VALUE_LENGTH);
        $stored = null;
        if ($tooLong !== []) {
            $stored = $this->loadMetadata($zoneId);
            $storedValues = array_column(array_filter(
                $stored,
                static fn(array $row): bool => strtoupper((string)$row['kind']) === $kind
            ), 'content');
            // Each stored copy of a long value may be kept once; extra copies are new values
            $heldCopies = array_count_values(array_map('strval', $storedValues));
            $overCopies = false;
            foreach (array_count_values($tooLong) as $value => $copies) {
                $overCopies = $overCopies || $copies > ($heldCopies[$value] ?? 0);
            }
            if ($overCopies) {
                return 'Metadata values must be at most ' . MetadataDefinitions::MAX_VALUE_LENGTH . ' bytes';
            }
        }

        $stored ??= $this->loadMetadata($zoneId);
        $kinds = array_unique(array_map(static fn(array $row): string => strtoupper((string)$row['kind']), $stored));
        if (!in_array($kind, $kinds, true) && count($kinds) >= MetadataDefinitions::MAX_KINDS_PER_ZONE) {
            return 'A zone accepts at most ' . MetadataDefinitions::MAX_KINDS_PER_ZONE . ' metadata kinds';
        }

        return null;
    }

    /**
     * How many values the kind holds now; a kind stored over the cap may be re-saved or shrunk, never grown.
     */
    private function storedValueCount(int $zoneId, string $kind): int
    {
        $count = 0;
        foreach ($this->loadMetadata($zoneId) as $row) {
            if (strtoupper((string)$row['kind']) === $kind) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Group flat metadata rows into kind => values structure.
     *
     * @param array<int, array{kind: string, content: string}> $rows
     * @return array<int, array{kind: string, values: array<string>}>
     */
    private function groupMetadataByKind(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $kind = $row['kind'];
            if (!isset($grouped[$kind])) {
                $grouped[$kind] = [];
            }
            $grouped[$kind][] = $row['content'];
        }

        $result = [];
        foreach ($grouped as $kind => $values) {
            $result[] = ['kind' => $kind, 'values' => $values];
        }

        usort($result, fn($a, $b) => strcmp($a['kind'], $b['kind']));
        return $result;
    }

    /**
     * Save metadata values for a specific kind.
     *
     * @param array<string> $values
     * @return bool True on success
     */
    private function saveMetadataKind(int $zoneId, string $kind, array $values): bool
    {
        if ($this->apiClient !== null) {
            $zone = $this->zoneRepository->getZone($zoneId);
            if ($zone === null) {
                return false;
            }
            $zoneObj = new Zone($zone['name']);

            if ($kind === 'SOA-EDIT-API') {
                return $this->apiClient->updateZoneProperties($zone['name'], ['soa_edit_api' => $values[0] ?? '']);
            }

            return $this->apiClient->updateZoneMetadata($zoneObj, $kind, $values);
        }

        // DB backend: load current metadata, replace this kind, save all
        $currentRows = $this->zoneRepository->getDomainMetadata($zoneId);
        $newRows = array_filter($currentRows, fn($row) => strtoupper($row['kind']) !== $kind);
        foreach ($values as $value) {
            $newRows[] = ['kind' => $kind, 'content' => $value];
        }
        return $this->zoneRepository->replaceDomainMetadata($zoneId, array_values($newRows));
    }

    /**
     * Delete all metadata values for a specific kind.
     *
     * @return bool True on success
     */
    private function deleteMetadataKindStorage(int $zoneId, string $kind): bool
    {
        if ($this->apiClient !== null) {
            $zone = $this->zoneRepository->getZone($zoneId);
            if ($zone === null) {
                return false;
            }

            if ($kind === 'SOA-EDIT-API') {
                return $this->apiClient->updateZoneProperties($zone['name'], ['soa_edit_api' => '']);
            }

            $zoneObj = new Zone($zone['name']);
            return $this->apiClient->deleteZoneMetadata($zoneObj, $kind);
        }

        // DB backend: load current metadata, remove this kind, save remaining
        $currentRows = $this->zoneRepository->getDomainMetadata($zoneId);
        $newRows = array_filter($currentRows, fn($row) => strtoupper($row['kind']) !== $kind);
        return $this->zoneRepository->replaceDomainMetadata($zoneId, array_values($newRows));
    }
}
