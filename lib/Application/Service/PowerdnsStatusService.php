<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Application\Service;

use Exception;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Infrastructure\Api\HttpClient;

class PowerdnsStatusService
{
    private PowerdnsApiClient $apiClient;
    private bool $apiEnabled;
    private string $apiUrl;
    private string $apiKey;
    private string $serverName;

    public function __construct()
    {
        $config = ConfigurationManager::getInstance();
        $this->apiUrl = $config->get('pdns_api', 'url', '');
        $this->apiKey = $config->get('pdns_api', 'key', '');
        $this->serverName = $config->get('pdns_api', 'server_name', 'localhost');
        $this->apiEnabled = !empty($this->apiUrl) && !empty($this->apiKey);

        if ($this->apiEnabled) {
            $httpClient = new HttpClient($this->apiUrl, $this->apiKey);
            $this->apiClient = new PowerdnsApiClient($httpClient, $this->serverName);
        }
    }

    public function isApiEnabled(): bool
    {
        return $this->apiEnabled;
    }

    public function getServerStatus(): array
    {
        if (!$this->apiEnabled) {
            return [
                'error' => 'PowerDNS API is not configured',
                'configured' => false,
                'running' => false
            ];
        }

        try {
            // Get server info first (for version and daemon_type)
            $serverInfo = $this->apiClient->getServerInfo();

            // Get configuration data (for uptime and other metrics)
            $configData = $this->apiClient->getConfig();

            // Get statistics metrics
            $metrics = [];
            try {
                $metricsData = $this->apiClient->getMetrics();
                // Process metrics data
                if (!empty($metricsData)) {
                    foreach ($metricsData as $metric) {
                        if (isset($metric['name'], $metric['value'])) {
                            $metrics[$metric['name']] = $metric['value'];
                        }
                    }
                }
            } catch (Exception $e) {
                // Metrics endpoint might not be available, continue without metrics
            }

            // Extract essential information
            $status = [
                'running' => true,
                'daemon_type' => $serverInfo['daemon_type'] ?? 'unknown',
                'version' => $serverInfo['version'] ?? 'unknown',
                'configured' => true,
                'server_name' => $this->serverName,
                'id' => $serverInfo['id'] ?? $this->serverName,
                'metrics' => $metrics
            ];

            // Add server metrics if available
            if (isset($configData['uptime'])) {
                $status['uptime'] = $this->formatUptime($configData['uptime']);
                $status['uptime_seconds'] = $configData['uptime'];
            }

            // Use uptime from metrics if available
            if (isset($metrics['uptime'])) {
                $uptimeSeconds = (int)$metrics['uptime'];
                $status['uptime'] = $this->formatUptime($uptimeSeconds);
                $status['uptime_seconds'] = $uptimeSeconds;
                $status['formatted_uptime'] = $this->formatUptimeHuman($uptimeSeconds);
            }

            return $status;
        } catch (ApiErrorException $e) {
            return [
                'error' => $e->getMessage(),
                'running' => false,
                'configured' => true,
                'server_name' => $this->serverName
            ];
        } catch (Exception $e) {
            return [
                'error' => 'An unexpected error occurred: ' . $e->getMessage(),
                'running' => false,
                'configured' => true,
                'server_name' => $this->serverName
            ];
        }
    }

    public function checkSlaveServerStatus(array $slaveServers): array
    {
        $results = [];

        if (!$this->apiEnabled || empty($slaveServers)) {
            return $results;
        }

        try {
            // Check each slave server
            foreach ($slaveServers as $server) {
                $results[$server] = [
                    'ip' => $server,
                    'status' => 'unknown',
                    'lastChecked' => date('Y-m-d H:i:s'),
                ];

                // Basic connectivity check - try to connect to DNS port (53)
                $errno = 0;
                $errstr = '';
                $timeout = 2; // 2 second timeout
                $connection = @fsockopen($server, 53, $errno, $errstr, $timeout);

                if (!$connection) {
                    $results[$server]['status'] = 'unreachable';
                    $results[$server]['error'] = "Cannot connect to DNS port: $errstr";
                } else {
                    fclose($connection);
                    $results[$server]['status'] = 'ok';
                }
            }

            return $results;
        } catch (ApiErrorException $e) {
            return [
                'error' => $e->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'error' => 'An unexpected error occurred: ' . $e->getMessage()
            ];
        }
    }

    private function formatUptime(int $uptimeSeconds): string
    {
        $days = floor($uptimeSeconds / 86400);
        $hours = floor(($uptimeSeconds % 86400) / 3600);
        $minutes = floor(($uptimeSeconds % 3600) / 60);
        $seconds = $uptimeSeconds % 60;

        $uptime = '';
        if ($days > 0) {
            $uptime .= "$days days, ";
        }

        return $uptime . sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Format uptime in a human-readable format
     *
     * @param int $uptimeSeconds Uptime in seconds
     * @return string Formatted uptime (e.g. "5d 3h 45m" or "12h 30m" or "5m")
     */
    private function formatUptimeHuman(int $uptimeSeconds): string
    {
        $days = floor($uptimeSeconds / 86400);
        $hours = floor(($uptimeSeconds % 86400) / 3600);
        $minutes = floor(($uptimeSeconds % 3600) / 60);

        // Format based on the largest unit available
        if ($days > 0) {
            return sprintf('%dd %dh %dm', $days, $hours, $minutes);
        } elseif ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        } else {
            return sprintf('%dm', $minutes);
        }
    }
}
