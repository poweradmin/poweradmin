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
    private string $displayName;
    private string $serverName;

    public function __construct()
    {
        $config = ConfigurationManager::getInstance();
        $this->apiUrl = $config->get('pdns_api', 'url', '');
        $this->apiKey = $config->get('pdns_api', 'key', '');
        $this->displayName = $config->get('pdns_api', 'display_name', '');
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
                // Try to get metrics from the PowerDNS API
                $metricsData = $this->apiClient->getMetrics();

                // Process metrics data from the API
                if (!empty($metricsData)) {
                    foreach ($metricsData as $metric) {
                        if (isset($metric['name'], $metric['value'])) {
                            $metrics[$metric['name']] = $metric['value'];
                        }
                    }
                }

                // Try to fetch and parse raw Prometheus metrics if available
                // Only do this if we have API URL configured (extract base URL)
                if (!empty($this->apiUrl)) {
                    $parsedUrl = parse_url($this->apiUrl);
                    if (isset($parsedUrl['scheme'], $parsedUrl['host'])) {
                        $port = isset($parsedUrl['port']) ? $parsedUrl['port'] : '8081';
                        $metricsUrl = "{$parsedUrl['scheme']}://{$parsedUrl['host']}:{$port}/metrics";

                        // Validate URL before fetching to prevent path traversal
                        if ($this->isSecureUrl($metricsUrl)) {
                            // Fetch metrics in Prometheus format
                            $rawMetrics = @file_get_contents($metricsUrl);
                            if ($rawMetrics !== false) {
                                // Parse Prometheus-style metrics
                                $prometheusMetrics = $this->parsePrometheusMetrics($rawMetrics);
                                // Merge with existing metrics, with Prometheus metrics taking precedence
                                $metrics = array_merge($metrics, $prometheusMetrics);
                                // Store metric metadata for UI display
                                $status['metric_info'] = $this->getMetricInfo($rawMetrics);
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Metrics endpoint might not be available, continue without metrics
            }

            // Prepare metric categories for UI display
            $metricCategories = $this->categorizeMetrics($metrics);

            // Verify that PDNS server is running
            $pdnsServerRunning = !empty($serverInfo);

            // Extract essential information
            $status = [
                'running' => $pdnsServerRunning,
                'daemon_type' => $serverInfo['daemon_type'] ?? 'unknown',
                'version' => $serverInfo['version'] ?? 'unknown',
                'configured' => true,
                'display_name' => $this->displayName,
                'server_name' => $this->serverName,
                'id' => $serverInfo['id'] ?? $this->serverName,
                'metrics' => $metrics,
                'metric_categories' => $metricCategories
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

    /**
     * Parse Prometheus-format metrics into a associative array
     *
     * @param string $rawMetricsText Raw Prometheus metrics text
     * @return array Parsed metrics as name => value
     */
    private function parsePrometheusMetrics(string $rawMetricsText): array
    {
        $metrics = [];
        $lines = explode("\n", $rawMetricsText);

        foreach ($lines as $line) {
            // Skip empty lines, comments, and HELP/TYPE lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Extract metric name and value
            if (preg_match('/^([a-zA-Z0-9_]+)(?:\{.*?\})?\s+([0-9e\.\+\-]+)$/', $line, $matches)) {
                $metricName = $matches[1];
                $metricValue = $matches[2];
                $metrics[$metricName] = $metricValue;
            }
        }

        return $metrics;
    }

    /**
     * Extract metric metadata (description and type) from Prometheus metrics
     *
     * @param string $rawMetricsText Raw Prometheus metrics text
     * @return array Metric info as name => [description, type]
     */
    private function getMetricInfo(string $rawMetricsText): array
    {
        $metricInfo = [];
        $lines = explode("\n", $rawMetricsText);

        foreach ($lines as $line) {
            // Extract HELP lines
            if (preg_match('/^# HELP ([a-zA-Z0-9_]+) (.+)$/', $line, $matches)) {
                $metricName = $matches[1];
                $description = $matches[2];
                if (!isset($metricInfo[$metricName])) {
                    $metricInfo[$metricName] = ['description' => '', 'type' => ''];
                }
                $metricInfo[$metricName]['description'] = $description;
            }

            // Extract TYPE lines
            if (preg_match('/^# TYPE ([a-zA-Z0-9_]+) (.+)$/', $line, $matches)) {
                $metricName = $matches[1];
                $type = $matches[2];
                if (!isset($metricInfo[$metricName])) {
                    $metricInfo[$metricName] = ['description' => '', 'type' => ''];
                }
                $metricInfo[$metricName]['type'] = $type;
            }
        }

        return $metricInfo;
    }

    /**
     * Categorize metrics for UI display
     *
     * @param array $metrics Metrics data
     * @return array Categorized metrics
     */
    private function categorizeMetrics(array $metrics): array
    {
        $categories = [
            'performance' => [
                'title' => 'Performance',
                'metrics' => [],
                'color' => 'success'
            ],
            'queries' => [
                'title' => 'Query Statistics',
                'metrics' => [],
                'color' => 'primary'
            ],
            'cache' => [
                'title' => 'Cache',
                'metrics' => [],
                'color' => 'info'
            ],
            'dnssec' => [
                'title' => 'DNSSEC',
                'metrics' => [],
                'color' => 'secondary'
            ],
            'errors' => [
                'title' => 'Errors',
                'metrics' => [],
                'color' => 'warning'
            ],
            'other' => [
                'title' => 'Other Metrics',
                'metrics' => [],
                'color' => 'light'
            ]
        ];

        // Categorization rules based on metric name prefixes
        foreach ($metrics as $name => $value) {
            // Skip array values or convert them to string representation
            if (is_array($value)) {
                // For now, skip array values as they're causing issues
                continue;
            }

            // Cast numeric strings to actual numbers
            if (is_string($value) && is_numeric($value)) {
                $value = $value + 0; // Convert to int or float
            }

            // Skip empty or invalid values
            if (empty($name)) {
                continue;
            }

            if (
                stripos($name, 'latency') !== false ||
                stripos($name, 'uptime') !== false ||
                stripos($name, 'msec') !== false
            ) {
                $categories['performance']['metrics'][$name] = $value;
            } elseif (
                stripos($name, 'cache') !== false ||
                      stripos($name, 'hit') !== false ||
                      stripos($name, 'miss') !== false
            ) {
                $categories['cache']['metrics'][$name] = $value;
            } elseif (
                stripos($name, 'dnssec') !== false ||
                      stripos($name, 'security') !== false ||
                      stripos($name, 'crypto') !== false
            ) {
                $categories['dnssec']['metrics'][$name] = $value;
            } elseif (
                stripos($name, 'query') !== false ||
                      stripos($name, 'answer') !== false ||
                      stripos($name, 'request') !== false ||
                      stripos($name, 'response') !== false ||
                      stripos($name, 'udp') !== false ||
                      stripos($name, 'tcp') !== false
            ) {
                $categories['queries']['metrics'][$name] = $value;
            } elseif (
                stripos($name, 'error') !== false ||
                      stripos($name, 'fail') !== false ||
                      stripos($name, 'corrupt') !== false ||
                      stripos($name, 'timeout') !== false ||
                      stripos($name, 'servfail') !== false
            ) {
                $categories['errors']['metrics'][$name] = $value;
            } else {
                $categories['other']['metrics'][$name] = $value;
            }
        }

        // Remove empty categories
        foreach ($categories as $key => $category) {
            if (empty($category['metrics'])) {
                unset($categories[$key]);
            }
        }

        return $categories;
    }

    /**
     * Validates that a URL uses only secure HTTP/HTTPS schemes
     *
     * @param string $url The URL to validate
     * @return bool True if URL is valid and uses HTTP/HTTPS scheme
     */
    private function isSecureUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parsedUrl = parse_url($url);
        return isset($parsedUrl['scheme']) && in_array($parsedUrl['scheme'], ['http', 'https'], true);
    }
}
