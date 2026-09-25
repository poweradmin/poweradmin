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
 * RESTful API v2 controller for the PowerDNS server status
 *
 * Exposes the data behind the /tools/pdns-status page to API clients such as
 * monitoring systems.
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\PowerdnsStatusService;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

class ServerStatusController extends PublicApiController
{
    private ApiPermissionService $apiPermissionService;
    private PowerdnsStatusService $statusService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->apiPermissionService = new ApiPermissionService($this->db);
        $this->statusService = new PowerdnsStatusService($this->logger);
    }

    public function run(): void
    {
        $response = match ($this->request->getMethod()) {
            'GET' => $this->getStatus(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    #[OA\Get(
        path: '/v2/server/status',
        operationId: 'v2GetServerStatus',
        description: 'Returns the status of the PowerDNS server behind Poweradmin (version, uptime and metrics). Intended for monitoring systems. Requires the server_status_view permission (administrators have it implicitly).',
        summary: 'Get PowerDNS server status',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['server'],
        parameters: [
            new OA\Parameter(
                name: 'metrics',
                description: 'Comma-separated list of metric names to return. Omit to return all metrics.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'uptime,udp-queries')
            ),
            new OA\Parameter(
                name: 'include',
                description: 'Set to "slaves" to also probe the configured autoprimary (supermaster) servers. This is slower.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['slaves'])
            ),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'PowerDNS server status retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Server status retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'running', type: 'boolean', example: true),
                        new OA\Property(property: 'server_id', type: 'string', example: 'localhost'),
                        new OA\Property(property: 'daemon_type', type: 'string', example: 'authoritative'),
                        new OA\Property(property: 'version', type: 'string', example: '4.9.4'),
                        new OA\Property(property: 'uptime_seconds', type: 'integer', nullable: true, example: 86400),
                        new OA\Property(
                            property: 'metrics',
                            description: 'Metric name to value, as reported by PowerDNS',
                            type: 'object',
                            additionalProperties: new OA\AdditionalProperties(type: 'string')
                        ),
                        new OA\Property(
                            property: 'slaves',
                            description: 'Only present with include=slaves',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'ip', type: 'string', example: '192.0.2.10'),
                                    new OA\Property(property: 'status', type: 'string', enum: ['ok', 'unreachable', 'skipped']),
                                    new OA\Property(property: 'last_checked', type: 'string', nullable: true, example: '2026-09-25 12:00:00'),
                                    new OA\Property(property: 'error', type: 'string', nullable: true),
                                ],
                                type: 'object'
                            )
                        ),
                    ],
                    type: 'object'
                )
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 403, description: 'Forbidden - missing server_status_view permission, or the API key is restricted to specific zones')]
    #[OA\Response(response: 501, description: 'The PowerDNS API is not configured')]
    #[OA\Response(response: 503, description: 'The PowerDNS server is not reachable')]
    protected function getStatus(): JsonResponse
    {
        // Server status is not tied to any zone, so a zone-restricted key must not see it.
        if ($this->getApiKeyScope()->hasZoneRestriction()) {
            return $this->returnApiError('Forbidden: this API key is restricted to specific zones', 403);
        }

        if (!$this->canViewServerStatus()) {
            return $this->returnApiError('You do not have permission to view the PowerDNS server status', 403);
        }

        if (!$this->statusService->isApiEnabled()) {
            return $this->returnApiError('The PowerDNS server status requires the PowerDNS API to be configured', 501);
        }

        try {
            $status = $this->statusService->getServerStatus(false);
        } catch (Exception $e) {
            return $this->handleException($e, 'Failed to retrieve PowerDNS server status', 'Failed to retrieve server status');
        }

        if (!empty($status['error']) || empty($status['running'])) {
            return $this->returnApiResponse(
                ['running' => false],
                false,
                'PowerDNS server is not reachable',
                503
            );
        }

        $data = [
            'running' => true,
            'server_id' => (string)($status['id'] ?? ''),
            'daemon_type' => (string)($status['daemon_type'] ?? 'unknown'),
            'version' => (string)($status['version'] ?? 'unknown'),
            'uptime_seconds' => isset($status['uptime_seconds']) ? (int)$status['uptime_seconds'] : null,
            'metrics' => $this->filterMetrics($status['metrics'] ?? []),
        ];

        if ($this->request->query->get('include') === 'slaves') {
            $data['slaves'] = $this->getSlaveStatus();
        }

        return $this->returnApiResponse($data, true, 'Server status retrieved successfully');
    }

    /**
     * Administrators always pass; everyone else needs the dedicated monitoring permission.
     */
    private function canViewServerStatus(): bool
    {
        $userId = $this->authenticatedUserId;

        return $this->apiPermissionService->userHasPermission($userId, 'user_is_ueberuser')
            || $this->apiPermissionService->userHasPermission($userId, 'server_status_view');
    }

    /**
     * Reduce the metrics to the names requested via ?metrics=a,b,c.
     *
     * @param array<string, mixed> $metrics
     * @return array<string, string>
     */
    private function filterMetrics(array $metrics): array
    {
        $requested = trim((string)$this->request->query->get('metrics', ''));
        if ($requested !== '') {
            $names = array_filter(array_map('trim', explode(',', $requested)), 'strlen');
            $metrics = array_intersect_key($metrics, array_flip($names));
        }

        // PowerDNS reports metric values as strings; keep one type for API clients.
        $result = [];
        foreach ($metrics as $name => $value) {
            if (is_scalar($value)) {
                $result[(string)$name] = (string)$value;
            }
        }

        ksort($result);
        return $result;
    }

    /**
     * @return array<int, array{ip: string, status: string, last_checked: ?string, error: ?string}>
     */
    private function getSlaveStatus(): array
    {
        $supermasterManager = DnsServiceFactory::createSupermasterManager($this->db, $this->getConfig());
        $slaveServers = $supermasterManager->getSlaveServerIPs();
        if (empty($slaveServers)) {
            return [];
        }

        $result = [];
        foreach ($this->statusService->checkSlaveServerStatus($slaveServers) as $slave) {
            $result[] = [
                'ip' => (string)$slave['ip'],
                'status' => (string)$slave['status'],
                'last_checked' => ($slave['lastChecked'] ?? '') !== '' ? $slave['lastChecked'] : null,
                'error' => $slave['error'] ?? null,
            ];
        }

        return $result;
    }
}
