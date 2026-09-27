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
use Poweradmin\Application\Http\RefusalStatus;
use Poweradmin\Application\Service\Backend\DnsBackendProviderFactory;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Service\Zone\DnssecKeyOutcome;
use Poweradmin\Domain\Service\Zone\DnssecKeyResult;
use Poweradmin\Domain\Service\Zone\DnssecKeyService;
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
    protected DomainRepositoryInterface $domainRepository;
    protected ApiPermissionService $apiPermissionService;
    protected ?PowerdnsApiClient $apiClient = null;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->domainRepository = $this->services()->domainRepository();
        $this->apiPermissionService = $this->services()->apiPermissionService();

        // Key management needs the PowerDNS API; createApiClient() returns null when it is not configured.
        $this->apiClient = DnsBackendProviderFactory::createApiClient($this->config, $this->logger);
    }

    public function run(): void
    {
        if (($this->pathParameters['action'] ?? null) === 'import') {
            $this->sendAndHalt($this->request->getMethod() === 'POST' ? $this->importKey() : $this->methodNotAllowed(['POST']));
        }

        $hasKeyId = isset($this->pathParameters['key_id']);

        $response = match ($this->request->getMethod()) {
            'GET' => $hasKeyId ? $this->getKey() : $this->listKeys(),
            'POST' => $hasKeyId ? $this->methodNotAllowed(['GET', 'PATCH', 'DELETE']) : $this->addKey(),
            'PATCH' => $hasKeyId ? $this->updateKey() : $this->methodNotAllowed(['GET', 'POST']),
            'DELETE' => $hasKeyId ? $this->deleteKey() : $this->methodNotAllowed(['GET', 'POST']),
            default => $this->methodNotAllowed($hasKeyId ? ['GET', 'PATCH', 'DELETE'] : ['GET', 'POST']),
        };

        $this->sendAndHalt($response);
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
            $result = $this->keyService()->listKeys($zoneName);
            if ($result->refusal !== null) {
                return $this->refused($result);
            }
            return $this->returnApiResponse(array_map([$this, 'formatKey'], $result->keys), true, 'DNSSEC keys retrieved successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'ZoneDnssecKeysController::listKeys', 'Failed to retrieve DNSSEC keys');
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
            $result = $this->keyService()->findKey($zoneName, $this->keyIdFromPath());
            if ($result->key === null) {
                return $this->refused($result);
            }
            return $this->returnApiResponse($this->formatKey($result->key), true, 'DNSSEC key retrieved successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'ZoneDnssecKeysController::getKey', 'Failed to retrieve DNSSEC key');
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

        $data = $this->getValidatedJsonBody();
        if ($data === null) {
            return $this->returnApiError('Invalid JSON body', 400);
        }

        $type = $data['type'] ?? null;
        $type = is_string($type) ? strtolower($type) : '';
        $algorithm = $data['algorithm'] ?? null;
        $algorithm = is_string($algorithm) ? strtolower($algorithm) : '';
        $bits = $data['bits'] ?? null;
        $bits = is_int($bits) || (is_string($bits) && ctype_digit($bits)) ? (int)$bits : null;

        $capabilities = $this->getPdnsCapabilities();
        $invalid = $this->keyService()->validateNewKey($type, $algorithm, $bits, $capabilities);
        if ($invalid !== null) {
            return $this->returnApiError($this->invalidKeyMessage($invalid, $algorithm, $bits), RefusalStatus::of($invalid->refusal ?? Refusal::INVALID_INPUT));
        }

        $active = $this->inputBool($data, 'active', false);
        if ($active === null) {
            return $this->returnApiError('Invalid field: active (boolean)', 400);
        }

        try {
            $result = $this->keyService()->addKey($zoneId, $zoneName, $type, $algorithm, (int)$bits, $active, $capabilities);
            if ($result->key === null) {
                return $this->refused($result, 'Failed to add DNSSEC key');
            }

            return $this->returnApiResponse($this->formatKey($result->key), true, 'DNSSEC key added successfully', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'ZoneDnssecKeysController::addKey', 'Failed to add DNSSEC key');
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

        $data = $this->getValidatedJsonBody();
        $active = $data !== null ? $this->inputBool($data, 'active') : null;
        if ($active === null) {
            return $this->returnApiError('Missing or invalid required field: active (boolean)', 400);
        }

        try {
            $result = $this->keyService()->setKeyActive($zoneId, $zoneName, $this->keyIdFromPath(), $active);
            if ($result->refusal !== null || $result->key === null) {
                return $this->refused($result, 'Failed to update DNSSEC key');
            }

            $message = $result->outcome === DnssecKeyOutcome::UNCHANGED
                ? ($active ? 'DNSSEC key already active' : 'DNSSEC key already inactive')
                : ($active ? 'DNSSEC key activated successfully' : 'DNSSEC key deactivated successfully');
            return $this->returnApiResponse($this->formatKey($result->key), true, $message);
        } catch (Exception $e) {
            return $this->handleException($e, 'ZoneDnssecKeysController::updateKey', 'Failed to update DNSSEC key');
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

        try {
            // An unreachable PowerDNS must not read as "not found", which clients take for "already gone"
            $result = $this->keyService()->removeKey($zoneId, $zoneName, $this->keyIdFromPath());
            if ($result->refusal !== null) {
                return $this->refused($result, 'Failed to delete DNSSEC key');
            }

            return $this->returnApiResponse(null, true, 'DNSSEC key deleted successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'ZoneDnssecKeysController::deleteKey', 'Failed to delete DNSSEC key');
        }
    }

    protected function keyService(): DnssecKeyService
    {
        return $this->services()->dnssecKeyService();
    }

    #[OA\Post(
        path: '/v2/zones/{id}/dnssec/keys/import',
        operationId: 'v2ImportZoneDnssecKey',
        description: 'Imports a DNSSEC key from an existing private key in the ISC/BIND format '
            . '("Private-key-format: v1.x", as written by dnssec-keygen or pdnsutil export-zone-key). '
            . 'PowerDNS derives algorithm and size from the key. PEM keys are not accepted by the PowerDNS HTTP API; '
            . 'import them with pdnsutil import-zone-key-pem or convert them first. '
            . 'The private key is never returned, logged or audited.',
        summary: 'Import a zone DNSSEC key',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'privatekey'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['ksk', 'zsk', 'csk'], example: 'csk'),
                    new OA\Property(
                        property: 'privatekey',
                        type: 'string',
                        example: "Private-key-format: v1.2\nAlgorithm: 13 (ECDSAP256SHA256)\nPrivateKey: Lt0v0Gol5Q6tsaPsYv6dcdeFk5yKzKjWMrhhm3E8wqU=\n",
                        description: 'Private key in the ISC/BIND format'
                    ),
                    new OA\Property(property: 'active', type: 'boolean', example: false, description: 'Create the key active; defaults to false'),
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
        description: 'DNSSEC key imported successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'DNSSEC key imported successfully'),
                new OA\Property(property: 'data', ref: '#/components/schemas/DnssecKey'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input, a private key PowerDNS rejected, or DNSSEC not enabled on the server')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone not found')]
    #[OA\Response(response: 409, description: 'Zone is presigned; DNSSEC is managed at the primary server')]
    #[OA\Response(response: 500, description: 'Failed to import DNSSEC key')]
    #[OA\Response(response: 501, description: 'DNSSEC key management requires the PowerDNS API')]
    #[OA\Response(response: 502, description: 'The request to PowerDNS failed')]
    protected function importKey(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];
        $zoneName = $this->resolveZone(true);
        if ($zoneName instanceof JsonResponse) {
            return $zoneName;
        }

        $data = $this->getValidatedJsonBody();
        if ($data === null) {
            return $this->returnApiError('Invalid JSON body', 400);
        }

        $type = $data['type'] ?? null;
        $type = is_string($type) ? strtolower($type) : '';
        $privateKey = $data['privatekey'] ?? null;
        $privateKey = is_string($privateKey) ? $privateKey : '';

        $active = $this->inputBool($data, 'active', false);
        if ($active === null) {
            return $this->returnApiError('Invalid field: active (boolean)', 400);
        }

        try {
            $result = $this->keyService()->importKey($zoneId, $zoneName, $type, $privateKey, $active);
            if ($result->key !== null) {
                return $this->returnApiResponse($this->formatKey($result->key), true, 'DNSSEC key imported successfully', 201);
            }

            // Fixed English texts: none of them may echo the private key
            $message = match ($result->outcome) {
                DnssecKeyOutcome::INVALID_TYPE => 'Missing or invalid required field: type (ksk, zsk or csk)',
                DnssecKeyOutcome::INVALID_PRIVATE_KEY => 'Missing or invalid required field: privatekey (an ISC/BIND private key with "Private-key-format: v1.x"; PEM keys can be imported with pdnsutil import-zone-key-pem)',
                DnssecKeyOutcome::KEY_REJECTED => 'PowerDNS rejected the private key',
                default => null,
            };
            if ($message !== null) {
                return $this->returnApiError($message, RefusalStatus::of($result->refusal ?? Refusal::INVALID_INPUT));
            }

            return $this->refused($result, 'Failed to import DNSSEC key');
        } catch (Exception $e) {
            return $this->handleException($e, 'ZoneDnssecKeysController::importKey', 'Failed to import DNSSEC key');
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

        $zoneName = $this->domainRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
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

        return $zoneName;
    }

    private function keyIdFromPath(): int
    {
        return (int)$this->pathParameters['key_id'];
    }

    /**
     * The error for a request the key service refused.
     *
     * @param string $failedText The text for a PowerDNS write that did not go through
     */
    private function refused(DnssecKeyResult $result, string $failedText = 'Failed to retrieve DNSSEC keys'): JsonResponse
    {
        $message = match ($result->outcome) {
            DnssecKeyOutcome::UNREACHABLE => 'Failed to retrieve DNSSEC keys from PowerDNS',
            DnssecKeyOutcome::SERVER_DISABLED => 'DNSSEC is not enabled on the server',
            DnssecKeyOutcome::PRESIGNED => 'DNSSEC for this zone is presigned and managed at the primary server',
            DnssecKeyOutcome::NOT_FOUND => 'DNSSEC key not found',
            default => $failedText,
        };

        return $this->returnApiError($message, RefusalStatus::of($result->refusal ?? Refusal::BACKEND_FAILURE));
    }

    private function invalidKeyMessage(DnssecKeyResult $invalid, string $algorithm, ?int $bits): string
    {
        return match (true) {
            $invalid->outcome === DnssecKeyOutcome::INVALID_TYPE => 'Missing or invalid required field: type (ksk, zsk or csk)',
            $invalid->outcome === DnssecKeyOutcome::INVALID_ALGORITHM => 'Missing or invalid required field: algorithm (one of: ' . implode(', ', $invalid->allowedAlgorithms) . ')',
            $bits === null => 'Missing or invalid required field: bits (integer)',
            default => $algorithm . ' requires ' . implode(' or ', $invalid->acceptedBits) . ' bits',
        };
    }

    /**
     * @return array{id: int, type: string, keytag: int, algorithm: ?string, algorithm_id: int, bits: int, active: bool, dnskey: ?string, ds: string[]}
     */
    private function formatKey(CryptoKey $key): array
    {
        $algorithmId = $key->getAlgorithmId();

        return [
            'id' => (int)$key->getId(),
            'type' => strtolower((string)$key->getType()),
            'keytag' => $key->getKeyTag(),
            'algorithm' => DnssecAlgorithmName::fromAlgorithmId($algorithmId),
            'algorithm_id' => $algorithmId,
            'bits' => (int)$key->getSize(),
            'active' => $key->isActive(),
            'dnskey' => $key->getDnskey(),
            'ds' => array_values($key->getDs()),
        ];
    }
}
