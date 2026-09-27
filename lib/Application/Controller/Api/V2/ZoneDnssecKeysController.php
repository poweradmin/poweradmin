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
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Model\Zone;
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
        new OA\Property(property: 'dnskey', type: 'string', nullable: true, example: '257 3 13 mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ=='),
        new OA\Property(property: 'ds', type: 'array', items: new OA\Items(type: 'string'), example: ['12345 13 2 3dd8ee7d9ab0c6d8e4b2fd8a7e1cb3a2b7b0d4e5f6a7b8c9d0e1f2a3b4c5d6e7'], description: 'DS records; empty for a ZSK'),
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

    /** @var CryptoKey[]|null The zone's keys, once a write has fetched them */
    private ?array $zoneKeys = null;

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
    #[OA\Response(response: 502, description: 'The request to PowerDNS failed')]
    protected function listKeys(): JsonResponse
    {
        $zoneName = $this->resolveZone(false);
        if ($zoneName instanceof JsonResponse) {
            return $zoneName;
        }

        try {
            $keys = $this->loadKeys($zoneName);
            if ($keys instanceof JsonResponse) {
                return $keys;
            }
            return $this->returnApiResponse(array_map([$this, 'formatKey'], $keys), true, 'DNSSEC keys retrieved successfully');
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
    #[OA\Response(response: 502, description: 'The request to PowerDNS failed')]
    protected function getKey(): JsonResponse
    {
        $zoneName = $this->resolveZone(false);
        if ($zoneName instanceof JsonResponse) {
            return $zoneName;
        }

        try {
            $key = $this->findKey($zoneName, $this->keyIdFromPath());
            if ($key instanceof JsonResponse) {
                return $key;
            }
            return $this->returnApiResponse($this->formatKey($key), true, 'DNSSEC key retrieved successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'Failed to get DNSSEC key', 'Failed to retrieve DNSSEC key');
        }
    }

    #[OA\Post(
        path: '/v2/zones/{id}/dnssec/keys',
        operationId: 'v2AddZoneDnssecKey',
        description: 'Adds a new DNSSEC key to a zone. Each algorithm has fixed key sizes: RSA algorithms take 1024 or 2048 bits, ecdsa256 and ed25519 256, ecdsa384 384 and ed448 456.',
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
                    new OA\Property(property: 'active', type: 'boolean', example: false, description: 'Create the key active; defaults to false, as in the web UI'),
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
    #[OA\Response(response: 502, description: 'The request to PowerDNS failed')]
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
        $bits = (string)(int)$bits;

        $specError = DnssecKeySpecValidator::apiErrorForAlgorithmBits($algorithm, $bits);
        if ($specError !== null) {
            return $this->returnApiError($specError, 400);
        }

        $active = $this->inputBool($data, 'active', false);
        if ($active === null) {
            return $this->returnApiError('Invalid field: active (boolean)', 400);
        }

        if (($refused = $this->checkWritable($zoneName)) !== null) {
            return $refused;
        }

        try {
            // PowerDNS answers with the key it created, so the id returned is always the new key's
            $created = $this->apiClient?->createZoneKey(new Zone($zoneName), new CryptoKey(null, $type, (int)$bits, $algorithm), $active);
            if ($created === null) {
                return $this->returnApiError('Failed to add DNSSEC key', 500);
            }

            (new AuditService($this->db))->logDnssecAddKey($zoneId, $zoneName, $type, $bits, $algorithm);

            return $this->returnApiResponse($this->formatKey($created), true, 'DNSSEC key added successfully', 201);
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
    #[OA\Response(response: 502, description: 'The request to PowerDNS failed')]
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

        if (($refused = $this->checkWritable($zoneName)) !== null) {
            return $refused;
        }

        $keyId = $this->keyIdFromPath();

        try {
            $key = $this->findKey($zoneName, $keyId);
            if ($key instanceof JsonResponse) {
                return $key;
            }

            // Already in the requested state: nothing to do (matches setting DNSSEC on/off).
            if ($key->isActive() === $active) {
                $message = $active ? 'DNSSEC key already active' : 'DNSSEC key already inactive';
                return $this->returnApiResponse($this->formatKey($key), true, $message);
            }

            $zone = new Zone($zoneName);
            $result = $active ? $this->apiClient?->activateZoneKey($zone, $key) : $this->apiClient?->deactivateZoneKey($zone, $key);
            if (!$result) {
                return $this->returnApiError('Failed to update DNSSEC key', 500);
            }

            (new AuditService($this->db))->logDnssecToggleKey($zoneId, $zoneName, $keyId, $active ? 'activate' : 'deactivate');

            $active ? $key->activate() : $key->deactivate();
            $message = $active ? 'DNSSEC key activated successfully' : 'DNSSEC key deactivated successfully';
            return $this->returnApiResponse($this->formatKey($key), true, $message);
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
    #[OA\Response(response: 502, description: 'The request to PowerDNS failed')]
    protected function deleteKey(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];
        $zoneName = $this->resolveZone(true);
        if ($zoneName instanceof JsonResponse) {
            return $zoneName;
        }

        if (($refused = $this->checkWritable($zoneName)) !== null) {
            return $refused;
        }

        $keyId = $this->keyIdFromPath();

        try {
            // An unreachable PowerDNS must not read as "not found", which clients take for "already gone"
            $key = $this->findKey($zoneName, $keyId);
            if ($key instanceof JsonResponse) {
                return $key;
            }

            if (!$this->apiClient?->removeZoneKey(new Zone($zoneName), $key)) {
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
     * data); changing them needs the DNSSEC management permission.
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

        return $zoneName;
    }

    /**
     * Refuse a key change for a presigned zone or a server without DNSSEC, or
     * return null when the change may go ahead.
     */
    private function checkWritable(string $zoneName): ?JsonResponse
    {
        // Ask for the keys first: the server-settings lookup below reads a failed request as "DNSSEC off"
        $keys = $this->loadKeys($zoneName);
        if ($keys instanceof JsonResponse) {
            return $keys;
        }
        $this->zoneKeys = $keys;

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

        return null;
    }

    private function keyIdFromPath(): int
    {
        return (int)$this->pathParameters['key_id'];
    }

    /**
     * @return CryptoKey[]|JsonResponse The zone's keys, or the error to send when PowerDNS could not be asked
     */
    private function loadKeys(string $zoneName): array|JsonResponse
    {
        $keys = $this->apiClient?->fetchZoneKeys(new Zone($zoneName));

        return $keys ?? $this->returnApiError('Failed to retrieve DNSSEC keys from PowerDNS', 502);
    }

    private function findKey(string $zoneName, int $keyId): CryptoKey|JsonResponse
    {
        $keys = $this->zoneKeys ?? $this->loadKeys($zoneName);
        if ($keys instanceof JsonResponse) {
            return $keys;
        }

        foreach ($keys as $key) {
            if ($key->getId() === $keyId) {
                return $key;
            }
        }

        return $this->returnApiError('DNSSEC key not found', 404);
    }

    /**
     * @return array{id: int, type: string, keytag: int, algorithm: ?string, algorithm_id: int, bits: int, active: bool, dnskey: ?string, ds: string[]}
     */
    private function formatKey(CryptoKey $key): array
    {
        $dnskey = $key->getDnskey();
        $fields = $dnskey !== null ? preg_split('/\s+/', trim($dnskey)) : [];
        $algorithmId = (int)($fields[2] ?? 0);

        return [
            'id' => (int)$key->getId(),
            'type' => strtolower((string)$key->getType()),
            'keytag' => $dnskey !== null ? self::keyTag($dnskey) : 0,
            'algorithm' => self::ALGORITHM_NAMES_BY_ID[$algorithmId] ?? null,
            'algorithm_id' => $algorithmId,
            'bits' => (int)$key->getSize(),
            'active' => $key->isActive(),
            'dnskey' => $dnskey,
            'ds' => array_values($key->getDs()),
        ];
    }

    /**
     * The key tag of a DNSKEY record in presentation format (RFC 4034, appendix B).
     */
    private static function keyTag(string $dnskey): int
    {
        $fields = preg_split('/\s+/', trim($dnskey));
        if (count($fields) < 4) {
            return 0;
        }

        $publicKey = base64_decode(implode('', array_slice($fields, 3)), true);
        if ($publicKey === false) {
            return 0;
        }

        $rdata = pack('nCC', (int)$fields[0], (int)$fields[1], (int)$fields[2]) . $publicKey;
        $sum = 0;
        $length = strlen($rdata);
        for ($i = 0; $i < $length; $i++) {
            $sum += ($i & 1) ? ord($rdata[$i]) : ord($rdata[$i]) << 8;
        }
        $sum += ($sum >> 16) & 0xFFFF;

        return $sum & 0xFFFF;
    }
}
