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
use Poweradmin\Application\Service\Backend\DnsBackendProviderFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Port\ZoneSigningInterface;
use Poweradmin\Domain\Service\Zone\ZoneSigningOutcome;
use Poweradmin\Domain\Service\Zone\ZoneSigningService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

/**
 * /api/v2/zones/{id}/dnssec: reports the signing status of a zone and signs or unsigns it.
 */
class ZoneDnssecController extends PublicApiController
{
    protected DomainRepositoryInterface $domainRepository;
    protected ApiPermissionService $apiPermissionService;
    protected ZoneSigningInterface $dnssecProvider;
    protected ?PowerdnsApiClient $apiClient = null;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->domainRepository = $this->services()->domainRepository();
        $this->apiPermissionService = $this->services()->apiPermissionService();
        $this->dnssecProvider = $this->services()->dnssecProvider();

        // DNSSEC works whenever the PowerDNS API is configured, independent of the
        // dns.backend setting; createApiClient() returns null when it is not.
        $this->apiClient = DnsBackendProviderFactory::createApiClient($this->config, $this->logger);
    }

    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => $this->getStatus(),
            'POST' => $this->setStatus(),
            default => $this->methodNotAllowed(['GET', 'POST']),
        };

        $response->send();
        exit;
    }

    // Enabling/disabling DNSSEC on an existing zone is an update, not a create,
    // so the POST here must not be treated as the default "create" operation.
    protected function requiredApiKeyOperations(): array
    {
        return strtoupper($this->request->getMethod()) === 'POST'
            ? [ApiKeyScope::OP_UPDATE]
            : [ApiKeyScope::OP_VIEW];
    }

    #[OA\Get(
        path: '/v2/zones/{id}/dnssec',
        operationId: 'v2GetZoneDnssec',
        description: 'Returns whether the zone is DNSSEC signed, along with the DS records and DNSKEY needed for registry submission',
        summary: 'Get zone DNSSEC status',
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
        description: 'DNSSEC status retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'DNSSEC status retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'enabled', type: 'boolean', example: true),
                        new OA\Property(property: 'presigned', type: 'boolean', description: 'Whether the zone is presigned (DNSSEC managed at the primary server)', example: false),
                        new OA\Property(
                            property: 'ds_records',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'key_tag', type: 'integer', example: 12345),
                                    new OA\Property(property: 'algorithm', type: 'integer', example: 13),
                                    new OA\Property(property: 'digest_type', type: 'integer', example: 2),
                                    new OA\Property(property: 'digest', type: 'string', example: 'ABC123DEF456'),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(property: 'dnskey', type: 'string', nullable: true, example: '257 3 13 ...'),
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
    #[OA\Response(response: 501, description: 'DNSSEC management requires the PowerDNS API')]
    protected function getStatus(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];

        if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
            return $scopeError;
        }

        $zoneName = $this->domainRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canViewZone($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to view this zone', 403);
        }

        if ($this->apiClient === null) {
            return $this->returnApiError('DNSSEC management requires the PowerDNS API to be configured', 501);
        }

        try {
            return $this->returnApiResponse(
                $this->buildStatus($zoneName),
                true,
                'DNSSEC status retrieved successfully'
            );
        } catch (Exception $e) {
            return $this->handleException($e, 'ZoneDnssecController::getStatus', 'Failed to retrieve DNSSEC status');
        }
    }

    #[OA\Post(
        path: '/v2/zones/{id}/dnssec',
        operationId: 'v2SetZoneDnssec',
        description: 'Enables or disables DNSSEC signing for a zone. Enabling creates the default keys and returns the resulting DS records and DNSKEY',
        summary: 'Enable or disable zone DNSSEC',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['enabled'],
                properties: [
                    new OA\Property(property: 'enabled', type: 'boolean', example: true),
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
            )
        ]
    )]
    #[OA\Response(response: 200, description: 'DNSSEC status updated successfully')]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone not found')]
    #[OA\Response(response: 409, description: 'Zone is presigned; DNSSEC is managed at the primary server')]
    #[OA\Response(response: 500, description: 'Failed to update DNSSEC status')]
    #[OA\Response(response: 501, description: 'DNSSEC management requires the PowerDNS API')]
    protected function setStatus(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];

        if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
            return $scopeError;
        }

        $zoneName = $this->domainRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canManageDnssec($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to manage DNSSEC for this zone', 403);
        }

        if ($this->apiClient === null) {
            return $this->returnApiError('DNSSEC management requires the PowerDNS API to be configured', 501);
        }

        $data = $this->getValidatedJsonBody();
        $enabled = $data !== null ? $this->inputBool($data, 'enabled') : null;
        if ($enabled === null) {
            return $this->returnApiError('Missing or invalid required field: enabled (boolean)', 400);
        }

        try {
            $signing = $this->zoneSigningService();
            $result = $enabled ? $signing->sign($zoneId, $zoneName) : $signing->unsign($zoneId, $zoneName);

            return match ($result->outcome) {
                ZoneSigningOutcome::SIGNED => $this->returnApiResponse($this->buildStatus($zoneName), true, 'DNSSEC enabled successfully'),
                ZoneSigningOutcome::UNSIGNED => $this->returnApiResponse($this->buildStatus($zoneName), true, 'DNSSEC disabled successfully'),
                ZoneSigningOutcome::ALREADY_SIGNED => $this->returnApiResponse($this->buildStatus($zoneName), true, 'DNSSEC already enabled'),
                ZoneSigningOutcome::NOT_SIGNED => $this->returnApiResponse($this->buildStatus($zoneName), true, 'DNSSEC already disabled'),
                ZoneSigningOutcome::SERVER_DISABLED => $this->returnApiError('DNSSEC is not enabled on the server', 400),
                ZoneSigningOutcome::PRESIGNED => $this->returnApiError('DNSSEC for this zone is presigned and managed at the primary server', 409),
                ZoneSigningOutcome::INVALID_ZONE => $this->returnApiError($result->detail, 400),
                default => $this->returnApiError('Failed to update DNSSEC status', 500),
            };
        } catch (Exception $e) {
            return $this->handleException($e, 'ZoneDnssecController::setStatus', 'Failed to update DNSSEC status');
        }
    }

    /**
     * The signing steps shared with the web pages.
     */
    protected function zoneSigningService(): ZoneSigningService
    {
        return $this->services()->zoneSigningService();
    }

    /**
     * Build the DNSSEC status payload (enabled flag, presigned flag, DS records, DNSKEY) for a zone.
     *
     * @return array{enabled: bool, presigned: bool, ds_records: array<int, array{key_tag: int, algorithm: int, digest_type: int, digest: string}>, dnskey: ?string}
     */
    private function buildStatus(string $zoneName): array
    {
        $enabled = $this->dnssecProvider->isZoneSecured($zoneName, $this->config);

        $dsRecords = [];
        $dnskey = null;

        if ($enabled) {
            $keys = $this->apiClient->getZoneKeys(new Zone($zoneName));
            foreach ($keys as $key) {
                $keyDs = $key->getDs();
                foreach ($keyDs as $ds) {
                    $dsRecords[] = self::parseDsRecord((string)$ds);
                }
                // The KSK/CSK is the key that carries DS records; expose its DNSKEY.
                // ds_records covers every key (what registries need); per-key DNSKEY
                // listing for multi-KSK rollovers is deferred to the key-management API.
                if ($dnskey === null && !empty($keyDs)) {
                    $dnskey = $key->getDnskey();
                }
            }
        }

        return [
            'enabled' => $enabled,
            'presigned' => $this->dnssecProvider->isZonePresigned($zoneName),
            'ds_records' => $dsRecords,
            'dnskey' => $dnskey,
        ];
    }

    /**
     * Parse a DS record zone-representation string ("keytag algo digesttype digest")
     * into structured fields for registry submission.
     *
     * @return array{key_tag: int, algorithm: int, digest_type: int, digest: string}
     */
    private static function parseDsRecord(string $ds): array
    {
        $parts = preg_split('/\s+/', trim($ds)) ?: [];

        return [
            'key_tag' => (int)($parts[0] ?? 0),
            'algorithm' => (int)($parts[1] ?? 0),
            'digest_type' => (int)($parts[2] ?? 0),
            'digest' => implode('', array_slice($parts, 3)),
        ];
    }
}
