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
 * RESTful API v2 controller for zone DNSSEC operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Domain\Service\ZoneValidationService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

class ZoneDnssecController extends PublicApiController
{
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
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
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

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canViewZone($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to view this zone', 403);
        }

        if ($this->apiClient === null) {
            return $this->returnApiError('DNSSEC management requires the PowerDNS API to be configured', 501);
        }

        $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return $this->returnApiError('Zone not found', 404);
        }

        try {
            return $this->returnApiResponse(
                $this->buildStatus($zoneName),
                true,
                'DNSSEC status retrieved successfully'
            );
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
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
    #[OA\Response(response: 500, description: 'Failed to update DNSSEC status')]
    #[OA\Response(response: 501, description: 'DNSSEC management requires the PowerDNS API')]
    protected function setStatus(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canManageDnssec($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to manage DNSSEC for this zone', 403);
        }

        if ($this->apiClient === null) {
            return $this->returnApiError('DNSSEC management requires the PowerDNS API to be configured', 501);
        }

        $data = json_decode($this->request->getContent(), true);
        $enabled = is_array($data) ? $this->inputBool($data, 'enabled') : null;
        if ($enabled === null) {
            return $this->returnApiError('Missing or invalid required field: enabled (boolean)', 400);
        }

        $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return $this->returnApiError('Zone not found', 404);
        }

        try {
            if ($enabled && !$this->dnssecProvider->isDnssecEnabled()) {
                return $this->returnApiError('DNSSEC is not enabled on the server', 400);
            }

            // No-op when the zone is already in the requested state: return the current
            // status without re-signing or bumping the SOA serial (matches the web UI).
            if ($this->dnssecProvider->isZoneSecured($zoneName, $this->config) === $enabled) {
                $message = $enabled ? 'DNSSEC already enabled' : 'DNSSEC already disabled';
                return $this->returnApiResponse($this->buildStatus($zoneName), true, $message);
            }

            // Mirror the web UI: validate the zone before signing so an invalid zone
            // is rejected without mutating the SOA serial, bump the serial before
            // signing (and after unsigning), and rectify after signing.
            if ($enabled) {
                $validationError = $this->validateZoneForSigning($zoneId, $zoneName);
                if ($validationError !== null) {
                    return $this->returnApiError($validationError, 400);
                }
                $this->bumpSoaSerial($zoneId);
                $result = $this->dnssecProvider->secureZone($zoneName);
            } else {
                $result = $this->dnssecProvider->unsecureZone($zoneName);
            }

            // Trust the provider's own result first: isZoneSecured() reports false on
            // API errors, so verifying state alone could mask a failed call.
            if (!$result) {
                return $this->returnApiError('Failed to update DNSSEC status', 500);
            }

            // buildStatus() re-reads the signed state, so use it to both confirm the
            // change took effect and return the resulting DS records in one pass.
            $status = $this->buildStatus($zoneName);
            if ($status['enabled'] !== $enabled) {
                return $this->returnApiError('Failed to update DNSSEC status', 500);
            }

            if ($enabled) {
                $this->dnssecProvider->rectifyZone($zoneName);
            } else {
                $this->bumpSoaSerial($zoneId);
            }

            $this->logDnssecChange($zoneId, $zoneName, $enabled);

            $message = $enabled ? 'DNSSEC enabled successfully' : 'DNSSEC disabled successfully';
            return $this->returnApiResponse($status, true, $message);
        } catch (Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Run the same DNSSEC pre-flight validation as the web UI (active SOA and NS
     * records present). Returns a formatted error message, or null when valid.
     */
    protected function validateZoneForSigning(int $zoneId, string $zoneName): ?string
    {
        $validator = new ZoneValidationService($this->getRepositoryFactory()->createRecordRepository());
        $result = $validator->validateZoneForDnssec($zoneId, $zoneName);
        return $result['valid'] ? null : $validator->getFormattedErrorMessage($result);
    }

    /**
     * Bump the zone's SOA serial so secondaries pick up the DNSSEC change,
     * matching the web UI sign/unsign flow.
     */
    protected function bumpSoaSerial(int $zoneId): void
    {
        (new DnsRecord($this->db, $this->config))->updateSOASerial($zoneId);
    }

    /**
     * Record a DNSSEC sign/unsign action in the audit log, matching the web UI.
     */
    protected function logDnssecChange(int $zoneId, string $zoneName, bool $enabled): void
    {
        $auditService = new AuditService($this->db);
        if ($enabled) {
            $auditService->logDnssecSignZone($zoneId, $zoneName);
        } else {
            $auditService->logDnssecUnsignZone($zoneId, $zoneName);
        }
    }

    /**
     * Build the DNSSEC status payload (enabled flag, DS records, DNSKEY) for a zone.
     *
     * @return array{enabled: bool, ds_records: array<int, array{key_tag: int, algorithm: int, digest_type: int, digest: string}>, dnskey: ?string}
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
