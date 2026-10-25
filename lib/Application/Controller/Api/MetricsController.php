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
 * Prometheus metrics about Poweradmin itself
 *
 * Serves counts of zones, DNSSEC-signed zones, users and optionally records in
 * the Prometheus text format at /api/metrics, for monitoring systems that
 * already scrape everything else.
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api;

use Poweradmin\Application\Service\Backend\DnsBackendProviderFactory;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Infrastructure\Metrics\PoweradminMetricsCollector;
use Poweradmin\Infrastructure\Metrics\PrometheusTextFormat;
use Poweradmin\Infrastructure\Session\ArraySession;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MetricsController extends PublicApiController
{
    public function run(): void
    {
        $response = match ($this->request->getMethod()) {
            'GET' => $this->metrics(),
            default => $this->methodNotAllowed(['GET']),
        };

        $this->sendAndHalt($response);
    }

    /**
     * With metrics.require_auth off the endpoint is public, like /api/health.
     * A disabled endpoint never asks for credentials, so it answers 404 to
     * everyone instead of revealing that it exists.
     */
    protected function requiresAuthentication(): bool
    {
        return $this->metricsEnabled() && (bool)$this->getConfig()->get('metrics', 'require_auth', true);
    }

    private function metricsEnabled(): bool
    {
        return (bool)$this->getConfig()->get('metrics', 'enabled', false);
    }

    private function metrics(): Response
    {
        if (!$this->metricsEnabled()) {
            return $this->returnApiError('Not Found', 404);
        }

        if ($this->requiresAuthentication()) {
            // The counts describe the whole installation, not a zone.
            if ($this->getApiKeyScope()->hasZoneRestriction()) {
                return $this->returnApiError('Forbidden: this API key is restricted to specific zones', 403);
            }
            if (!$this->services()->apiPermissionService()->userHasPermission($this->authenticatedUserId, Permission::PERM_SERVER_STATUS_VIEW)) {
                return $this->returnApiError('You do not have permission to view metrics', 403);
            }
        }

        try {
            $config = $this->getConfig();
            $apiBackend = DnsBackendProviderFactory::isApiBackend($config);
            $collector = new PoweradminMetricsCollector(
                $this->db,
                new TableNameService($config),
                // A scrape keeps no session, so the client records into a throwaway one; the health
                // check's timeout keeps a down PowerDNS (plus one retry) inside Prometheus's scrape_timeout
                $apiBackend ? DnsBackendProviderFactory::createApiClient($config, new ArraySession(), $this->logger, (int)$config->get('health', 'pdns_timeout', 2)) : null,
                $apiBackend,
                (bool)$config->get('metrics', 'count_records', false),
                $this->logger
            );

            return new Response(PrometheusTextFormat::render($collector->collect()), 200, [
                'Content-Type' => PrometheusTextFormat::CONTENT_TYPE,
                'Cache-Control' => 'no-store',
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Metrics collection failed: ' . $e->getMessage());
            return new Response("# metrics collection failed\n", 500, [
                'Content-Type' => PrometheusTextFormat::CONTENT_TYPE,
                'Cache-Control' => 'no-store',
            ]);
        }
    }
}
