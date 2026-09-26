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
 * RESTful API v2 controller for rectifying a DNSSEC signed zone
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
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Symfony\Component\HttpFoundation\JsonResponse;

class ZoneDnssecRectifyController extends PublicApiController
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
        $this->apiClient = DnsBackendProviderFactory::createApiClient($this->config, $this->logger);
    }

    public function run(): void
    {
        $response = match ($this->request->getMethod()) {
            'POST' => $this->rectify(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    // Rectifying changes an existing zone, so it needs update rather than create rights.
    protected function requiredApiKeyOperations(): array
    {
        return [ApiKeyScope::OP_UPDATE];
    }

    #[OA\Post(
        path: '/v2/zones/{id}/dnssec/rectify',
        operationId: 'v2RectifyZone',
        description: 'Rectifies a DNSSEC signed zone (recalculates ordername and auth fields). Only works for signed primary zones.',
        summary: 'Rectify a DNSSEC signed zone',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['zones'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'Zone ID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Zone rectified successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Zone rectified successfully'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Zone not found')]
    #[OA\Response(response: 409, description: 'Zone is not DNSSEC signed, is presigned or is a secondary zone')]
    #[OA\Response(response: 500, description: 'Failed to rectify zone')]
    #[OA\Response(response: 501, description: 'Rectifying requires the PowerDNS API')]
    protected function rectify(): JsonResponse
    {
        $zoneId = (int)$this->pathParameters['id'];

        if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
            return $scopeError;
        }

        if (!$this->zoneRepository->zoneExists($zoneId)) {
            return $this->returnApiError('Zone not found', 404);
        }

        if (!$this->apiPermissionService->canManageDnssec($this->authenticatedUserId, $zoneId)) {
            return $this->returnApiError('You do not have permission to manage DNSSEC for this zone', 403);
        }

        if ($this->apiClient === null) {
            return $this->returnApiError('Rectifying requires the PowerDNS API to be configured', 501);
        }

        $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return $this->returnApiError('Zone not found', 404);
        }

        // PowerDNS refuses to rectify secondary and unsigned zones; report that clearly
        // instead of passing on a generic backend error.
        if (strtoupper($this->zoneRepository->getDomainType($zoneId)) === 'SLAVE') {
            return $this->returnApiError('Secondary zones cannot be rectified', 409);
        }

        try {
            if ($this->dnssecProvider->isZonePresigned($zoneName)) {
                return $this->returnApiError('DNSSEC for this zone is presigned and managed at the primary server', 409);
            }

            if (!$this->dnssecProvider->isZoneSecured($zoneName, $this->config)) {
                return $this->returnApiError('Zone is not DNSSEC signed', 409);
            }

            if (!$this->dnssecProvider->rectifyZone($zoneName)) {
                return $this->returnApiError('Failed to rectify zone', 500);
            }

            return $this->returnApiResponse(null, true, 'Zone rectified successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'Failed to rectify zone', 'Failed to rectify zone');
        }
    }
}
