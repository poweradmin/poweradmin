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
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ZoneMetadataOutcome;
use Poweradmin\Domain\Service\ZoneMetadataResult;
use Poweradmin\Domain\Service\ZoneMetadataService;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

class ZoneMetadataController extends PublicApiController
{
    private ZoneRepositoryInterface $zoneRepository;
    private ApiPermissionService $apiPermissionService;
    private ZoneMetadataService $metadataService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = $this->createZoneRepository();
        $this->apiPermissionService = $this->createApiPermissionService();
        $this->metadataService = $this->createZoneMetadataService();
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

        if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
            return $scopeError;
        }

        $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canViewZoneMetadata($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to view zone metadata', 403);
        }

        try {
            $grouped = $this->groupMetadataByKind($this->metadataService->load($zoneId, $zoneName));

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

        if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
            return $scopeError;
        }

        $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canViewZoneMetadata($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to view zone metadata', 403);
        }

        try {
            $values = [];
            foreach ($this->metadataService->load($zoneId, $zoneName) as $row) {
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

        if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
            return $scopeError;
        }

        $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canEditZoneMeta($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to edit zone metadata', 403);
        }

        try {
            $data = json_decode($this->request->getContent(), true);

            if (!isset($data['values']) || !is_array($data['values'])) {
                return $this->returnApiError('Missing required field: values (array)', 400);
            }

            $result = $this->metadataService->replaceKind($zoneId, $zoneName, $kind, array_map('strval', $data['values']), $this->authenticatedUserId);
            if (!$result->isOk()) {
                return $this->refusalResponse($result, 'Failed to update metadata');
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

        if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
            return $scopeError;
        }

        $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canEditZoneMeta($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to edit zone metadata', 403);
        }

        try {
            $result = $this->metadataService->deleteKind($zoneId, $zoneName, $kind, $this->authenticatedUserId);
            if (!$result->isOk()) {
                return $this->refusalResponse($result, 'Failed to delete metadata');
            }

            return $this->returnApiResponse(null, true, 'Metadata deleted successfully');
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * The API wording and status for a refused metadata write.
     */
    private function refusalResponse(ZoneMetadataResult $result, string $writeFailureText): JsonResponse
    {
        $kind = $result->kind;

        return match ($result->outcome) {
            ZoneMetadataOutcome::INVALID_KIND => $this->returnApiError('Invalid metadata kind', 400),
            ZoneMetadataOutcome::EMPTY_VALUES => $this->returnApiError('Values array must not be empty. Use DELETE to remove metadata.', 400),
            ZoneMetadataOutcome::SINGLE_VALUE_ONLY => $this->returnApiError('Metadata kind ' . $kind . ' accepts only a single value', 400),
            ZoneMetadataOutcome::INVALID_VALUE => $this->returnApiError('Invalid value for ' . $kind . '. Allowed values: ' . implode(', ', $result->detail['options'] ?? []), 422),
            ZoneMetadataOutcome::COMPANION_REQUIRED => $this->returnApiError('Metadata kind ' . $kind . ' only takes effect together with ' . ($result->detail['companion'] ?? ''), 422),
            ZoneMetadataOutcome::OPERATOR_ONLY => $this->returnApiError('Metadata kind ' . $kind . ' can only be set by an administrator', 403),
            ZoneMetadataOutcome::SERVER_MANAGED => $this->returnApiError('Metadata kind ' . $kind . ' is maintained by PowerDNS', 403),
            ZoneMetadataOutcome::NO_API_ROUTE => $this->returnApiError('Metadata kind ' . $kind . ' is read-only', 403),
            ZoneMetadataOutcome::CUSTOM_PREFIX => $this->returnApiError('Custom metadata kind ' . $kind . ' must start with ' . ($result->detail['prefix'] ?? MetadataDefinitions::CUSTOM_KIND_API_PREFIX), 422),
            default => $this->returnApiError($writeFailureText, 500),
        };
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
}
