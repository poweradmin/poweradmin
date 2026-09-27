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
 * RESTful API v2 controller for DNSSEC key management of a zone
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Exception;
use OpenApi\Attributes as OA;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Enum\DnssecKeyType;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnssecKeySpecValidator;
use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Symfony\Component\HttpFoundation\JsonResponse;

#[OA\Schema(
    schema: 'DnssecKey',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'type', type: 'string', enum: ['ksk', 'zsk', 'csk'], example: 'csk'),
        new OA\Property(property: 'keytag', type: 'integer', example: 12345),
        new OA\Property(property: 'algorithm', type: 'string', nullable: true, example: 'ecdsa256'),
        new OA\Property(property: 'algorithm_id', type: 'integer', example: 13),
        new OA\Property(property: 'bits', type: 'integer', example: 256),
        new OA\Property(property: 'active', type: 'boolean', example: true),
    ],
    type: 'object'
)]
class ZoneDnssecKeysController extends PublicApiController
{
    /**
     * DNSSEC algorithm numbers (RFC 8624) mapped to the names PowerDNS uses.
     */
    private const ALGORITHM_NAMES_BY_ID = [
        5 => 'rsasha1',
        7 => 'rsasha1-nsec3-sha1',
        8 => 'rsasha256',
        10 => 'rsasha512',
        13 => 'ecdsa256',
        14 => 'ecdsa384',
        15 => 'ed25519',
        16 => 'ed448',
    ];

    protected ZoneRepositoryInterface $zoneRepository;
    protected ApiPermissionService $apiPermissionService;
    protected DnssecProvider $dnssecProvider;
    protected ?PowerdnsApiClient $apiClient = null;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = $this->createZoneRepository();
        $this->apiPermissionService = new ApiPermissionService($this->db);
        $this->dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);

        // Key management needs the PowerDNS API; createApiClient() returns null when it is not configured.
        $this->apiClient = DnsBackendProviderFactory::createApiClient($this->config, $this->logger);
    }

    public function run(): void
    {
        $hasKeyId = isset($this->pathParameters['key_id']);

        $response = match ($this->request->getMethod()) {
            'GET' => $hasKeyId ? $this->getKey() : $this->listKeys(),
            'POST' => $hasKeyId ? $this->returnApiError('Method not allowed', 405) : $this->addKey(),
            'PATCH' => $hasKeyId ? $this->updateKey() : $this->returnApiError('Method not allowed', 405),
            'DELETE' => $hasKeyId ? $this->deleteKey() : $this->returnApiError('Method not allowed', 405),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    #[OA\Get(
        path: '/v2/zones/{id}/dnssec/keys',
        operationId: 'v2ListZoneDnssecKeys',
        description: 'Lists the DNSSEC keys of a zone',
        summary: 'List zone DNSSEC keys',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'Zone ID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'DNSSEC keys retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'DNSSEC keys retrieved successfully'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DnssecKey')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone not found')]
    #[OA\Response(response: 501, description: 'DNSSEC key management requires the PowerDNS API')]
    protected function listKeys(): JsonResponse
    {
        $zoneName = $this->resolveZone(false);
        if ($zoneName instanceof JsonResponse) {
            return $zoneName;
        }

        try {
            $keys = array_map([$this, 'formatKey'], $this->dnssecProvider->getKeys($zoneName));
            return $this->returnApiResponse($keys, true, 'DNSSEC keys retrieved successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'Failed to list DNSSEC keys', 'Failed to retrieve DNSSEC keys');
        }
    }

    #[OA\Get(
        path: '/v2/zones/{id}/dnssec/keys/{key_id}',
        operationId: 'v2GetZoneDnssecKey',
        description: 'Returns a single DNSSEC key of a zone',
        summary: 'Get a zone DNSSEC key',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'Zone ID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'key_id', description: 'Key ID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'DNSSEC key retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'DNSSEC key retrieved successfully'),
                new OA\Property(property: 'data', ref: '#/components/schemas/DnssecKey'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone or key not found')]
    #[OA\Response(response: 501, description: 'DNSSEC key management requires the PowerDNS API')]
    protected function getKey(): JsonResponse
    {
        $zoneName = $this->resolveZone(false);
        if ($zoneName instanceof JsonResponse) {
            return $zoneName;
        }

        try {
            $key = $this->findKey($zoneName, $this->keyIdFromPath());
            if ($key === null) {
                return $this->returnApiError('DNSSEC key not found', 404);
            }
            return $this->returnApiResponse($key, true, 'DNSSEC key retrieved successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'Failed to get DNSSEC key', 'Failed to retrieve DNSSEC key');
        }
    }

    #[OA\Post(
        path: '/v2/zones/{id}/dnssec/keys',
        operationId: 'v2AddZoneDnssecKey',
        description: 'Adds a new DNSSEC key to a zone. Algorithm and bits follow the same rules as the web UI (e.g. ecdsa256 requires 256 bits).',
        summary: 'Add a zone DNSSEC key',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'algorithm', 'bits'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['ksk', 'zsk', 'csk'], example: 'csk'),
                    new OA\Property(property: 'algorithm', type: 'string', example: 'ecdsa256'),
                    new OA\Property(property: 'bits', type: 'integer', example: 256),
                ]
            )
        ),
        tags: ['zones'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'Zone ID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ]
    )]
    #[OA\Response(
        response: 201,
        description: 'DNSSEC key added successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'DNSSEC key added successfully'),
                new OA\Property(property: 'data', ref: '#/components/schemas/DnssecKey'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input or DNSSEC not enabled on the server')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone not found')]
    #[OA\Response(response: 409, description: 'Zone is presigned; DNSSEC is managed at the primary server')]
    #[OA\Response(response: 500, description: 'Failed to add DNSSEC key')]
    #[OA\Response(response: 501, description: 'DNSSEC key management requires the PowerDNS API')]
    protected function addKey(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];
        $zoneName = $this->resolveZone(true);
        if ($zoneName instanceof JsonResponse) {
            return $zoneName;
        }

        $data = json_decode($this->request->getContent(), true);
        if (!is_array($data)) {
            return $this->returnApiError('Invalid JSON body', 400);
        }

        $type = $data['type'] ?? null;
        if (!is_string($type) || !DnssecKeyType::isValid(strtolower($type))) {
            return $this->returnApiError('Missing or invalid required field: type (ksk, zsk or csk)', 400);
        }
        $type = strtolower($type);

        $allowedAlgorithms = DnssecAlgorithmName::getSupportedAlgorithmsForCapabilities($this->getPdnsCapabilities());
        $algorithm = $data['algorithm'] ?? null;
        if (!is_string($algorithm) || !in_array(strtolower($algorithm), $allowedAlgorithms, true)) {
            return $this->returnApiError(
                'Missing or invalid required field: algorithm (one of: ' . implode(', ', $allowedAlgorithms) . ')',
                400
            );
        }
        $algorithm = strtolower($algorithm);

        $bits = $data['bits'] ?? null;
        if (!is_int($bits) && !(is_string($bits) && ctype_digit($bits))) {
            return $this->returnApiError('Missing or invalid required field: bits (integer)', 400);
        }
        $bits = (string)$bits;
        if (!DnssecKeySpecValidator::isValidBits($bits)) {
            return $this->returnApiError(
                'Invalid bits (one of: ' . implode(', ', DnssecKeySpecValidator::VALID_BITS) . ')',
                400
            );
        }

        $specError = DnssecKeySpecValidator::validateAlgorithmBits($algorithm, $bits);
        if ($specError !== null) {
            return $this->returnApiError($specError, 400);
        }

        try {
            $existingIds = array_map(static fn(array $key) => (int)$key[0], $this->dnssecProvider->getKeys($zoneName));

            if (!$this->dnssecProvider->addZoneKey($zoneName, $type, (int)$bits, $algorithm)) {
                return $this->returnApiError('Failed to add DNSSEC key', 500);
            }

            (new AuditService($this->db))->logDnssecAddKey($zoneId, $zoneName, $type, $bits, $algorithm);

            // The provider only reports success, so find the new key by its ID.
            foreach ($this->dnssecProvider->getKeys($zoneName) as $key) {
                if (!in_array((int)$key[0], $existingIds, true)) {
                    return $this->returnApiResponse($this->formatKey($key), true, 'DNSSEC key added successfully', 201);
                }
            }

            return $this->returnApiResponse(null, true, 'DNSSEC key added successfully', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'Failed to add DNSSEC key', 'Failed to add DNSSEC key');
        }
    }

    #[OA\Patch(
        path: '/v2/zones/{id}/dnssec/keys/{key_id}',
        operationId: 'v2UpdateZoneDnssecKey',
        description: 'Activates or deactivates a DNSSEC key',
        summary: 'Activate or deactivate a zone DNSSEC key',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['active'],
                properties: [
                    new OA\Property(property: 'active', type: 'boolean', example: false),
                ]
            )
        ),
        tags: ['zones'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'Zone ID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'key_id', description: 'Key ID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'DNSSEC key updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'DNSSEC key deactivated successfully'),
                new OA\Property(property: 'data', ref: '#/components/schemas/DnssecKey'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input or DNSSEC not enabled on the server')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone or key not found')]
    #[OA\Response(response: 409, description: 'Zone is presigned; DNSSEC is managed at the primary server')]
    #[OA\Response(response: 500, description: 'Failed to update DNSSEC key')]
    #[OA\Response(response: 501, description: 'DNSSEC key management requires the PowerDNS API')]
    protected function updateKey(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];
        $zoneName = $this->resolveZone(true);
        if ($zoneName instanceof JsonResponse) {
            return $zoneName;
        }

        $data = json_decode($this->request->getContent(), true);
        $active = is_array($data) ? $this->inputBool($data, 'active') : null;
        if ($active === null) {
            return $this->returnApiError('Missing or invalid required field: active (boolean)', 400);
        }

        $keyId = $this->keyIdFromPath();

        try {
            $key = $this->findKey($zoneName, $keyId);
            if ($key === null) {
                return $this->returnApiError('DNSSEC key not found', 404);
            }

            // Already in the requested state: nothing to do (matches setting DNSSEC on/off).
            if ($key['active'] === $active) {
                $message = $active ? 'DNSSEC key already active' : 'DNSSEC key already inactive';
                return $this->returnApiResponse($key, true, $message);
            }

            $result = $active
                ? $this->dnssecProvider->activateZoneKey($zoneName, $keyId)
                : $this->dnssecProvider->deactivateZoneKey($zoneName, $keyId);
            if (!$result) {
                return $this->returnApiError('Failed to update DNSSEC key', 500);
            }

            (new AuditService($this->db))->logDnssecToggleKey($zoneId, $zoneName, $keyId, $active ? 'activate' : 'deactivate');

            $message = $active ? 'DNSSEC key activated successfully' : 'DNSSEC key deactivated successfully';
            return $this->returnApiResponse($this->findKey($zoneName, $keyId), true, $message);
        } catch (Exception $e) {
            return $this->handleException($e, 'Failed to update DNSSEC key', 'Failed to update DNSSEC key');
        }
    }

    #[OA\Delete(
        path: '/v2/zones/{id}/dnssec/keys/{key_id}',
        operationId: 'v2DeleteZoneDnssecKey',
        description: 'Deletes a DNSSEC key from a zone',
        summary: 'Delete a zone DNSSEC key',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'Zone ID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'key_id', description: 'Key ID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'DNSSEC key deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'DNSSEC key deleted successfully'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'DNSSEC not enabled on the server')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone or key not found')]
    #[OA\Response(response: 409, description: 'Zone is presigned; DNSSEC is managed at the primary server')]
    #[OA\Response(response: 500, description: 'Failed to delete DNSSEC key')]
    #[OA\Response(response: 501, description: 'DNSSEC key management requires the PowerDNS API')]
    protected function deleteKey(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];
        $zoneName = $this->resolveZone(true);
        if ($zoneName instanceof JsonResponse) {
            return $zoneName;
        }

        $keyId = $this->keyIdFromPath();

        try {
            if (!$this->dnssecProvider->keyExists($zoneName, $keyId)) {
                return $this->returnApiError('DNSSEC key not found', 404);
            }

            if (!$this->dnssecProvider->removeZoneKey($zoneName, $keyId)) {
                return $this->returnApiError('Failed to delete DNSSEC key', 500);
            }

            (new AuditService($this->db))->logDnssecDeleteKey($zoneId, $zoneName, $keyId);

            return $this->returnApiResponse(null, true, 'DNSSEC key deleted successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'Failed to delete DNSSEC key', 'Failed to delete DNSSEC key');
        }
    }

    /**
     * Run the checks shared by all key endpoints and return the zone name, or
     * the error response to send.
     *
     * Reading keys needs view access to the zone (the keys are public DNSKEY
     * data); changing them needs the DNSSEC management permission and is
     * refused for presigned zones and servers without DNSSEC.
     */
    private function resolveZone(bool $modify): string|JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];

        if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
            return $scopeError;
        }

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        $allowed = $modify
            ? $this->apiPermissionService->canManageDnssec($this->authenticatedUserId, $zoneId)
            : $this->apiPermissionService->canViewZone($this->authenticatedUserId, $zoneId);
        if (!$allowed) {
            return $this->returnApiError(
                $modify ? 'You do not have permission to manage DNSSEC for this zone' : 'You do not have permission to view this zone',
                403
            );
        }

        if ($this->apiClient === null) {
            return $this->returnApiError('DNSSEC key management requires the PowerDNS API to be configured', 501);
        }

        $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return $this->returnApiError('Zone not found', 404);
        }

        if ($modify) {
            try {
                if (!$this->dnssecProvider->isDnssecEnabled()) {
                    return $this->returnApiError('DNSSEC is not enabled on the server', 400);
                }
                if ($this->dnssecProvider->isZonePresigned($zoneName)) {
                    return $this->returnApiError('DNSSEC for this zone is presigned and managed at the primary server', 409);
                }
            } catch (Exception $e) {
                return $this->handleException($e, 'Failed to check DNSSEC state', 'Failed to check DNSSEC state');
            }
        }

        return $zoneName;
    }

    private function keyIdFromPath(): int
    {
        return (int)$this->pathParameters['key_id'];
    }

    /**
     * @return array{id: int, type: string, keytag: int, algorithm: ?string, algorithm_id: int, bits: int, active: bool}|null
     */
    private function findKey(string $zoneName, int $keyId): ?array
    {
        $key = $this->dnssecProvider->getZoneKey($zoneName, $keyId);
        return empty($key) ? null : $this->formatKey($key);
    }

    /**
     * Turn the provider's positional key array [id, TYPE, keytag, algorithm, bits, active]
     * into named fields.
     *
     * @param array<int, mixed> $key
     * @return array{id: int, type: string, keytag: int, algorithm: ?string, algorithm_id: int, bits: int, active: bool}
     */
    private function formatKey(array $key): array
    {
        $algorithmId = (int)($key[3] ?? 0);

        return [
            'id' => (int)($key[0] ?? 0),
            'type' => strtolower((string)($key[1] ?? '')),
            'keytag' => (int)($key[2] ?? 0),
            'algorithm' => self::ALGORITHM_NAMES_BY_ID[$algorithmId] ?? null,
            'algorithm_id' => $algorithmId,
            'bits' => (int)($key[4] ?? 0),
            'active' => (bool)($key[5] ?? false),
        ];
    }
}
