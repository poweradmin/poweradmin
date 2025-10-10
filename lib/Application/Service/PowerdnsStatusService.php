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
    private string $webserverUsername;
    private string $webserverPassword;

    public function __construct()
    {
        $config = ConfigurationManager::getInstance();
        $this->apiUrl = $config->get('pdns_api', 'url', '');
        $this->apiKey = $config->get('pdns_api', 'key', '');
        $this->displayName = $this->sanitizeDisplayName($config->get('pdns_api', 'display_name', 'PowerDNS'));
        $this->serverName = $config->get('pdns_api', 'server_name', 'localhost');
        $this->webserverUsername = $config->get('pdns_api', 'webserver_username', '');
        $this->webserverPassword = $config->get('pdns_api', 'webserver_password', '');
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
            $metricInfo = [];
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

                        // Fetch metrics in Prometheus format with optional Basic Auth
                        // URL validation happens inside fetchMetricsWithAuth()
                        $rawMetrics = $this->fetchMetricsWithAuth($metricsUrl);
                        if ($rawMetrics !== false) {
                            // Parse Prometheus-style metrics
                            $prometheusMetrics = $this->parsePrometheusMetrics($rawMetrics);
                            // Merge with existing metrics, with Prometheus metrics taking precedence
                            $metrics = array_merge($metrics, $prometheusMetrics);
                            // Store metric metadata for UI display
                            $metricInfo = $this->getMetricInfo($rawMetrics);
                        } else {
                            // Log failure to fetch Prometheus metrics (may require Basic Auth)
                            error_log("Failed to fetch Prometheus metrics from {$metricsUrl}. If PowerDNS webserver-password is enabled, configure 'pdns_api.webserver_username' and 'pdns_api.webserver_password' in settings.");
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

            // Add Prometheus metric info if available
            if (!empty($metricInfo)) {
                $status['metric_info'] = $metricInfo;
            }

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

            // Cast numeric strings to actual numbers (handles integers, floats, and scientific notation)
            if (is_string($value) && is_numeric($value)) {
                // Scientific notation (e.g., 1e-05) or decimal point requires float casting
                $value = (str_contains($value, '.') || stripos($value, 'e') !== false)
                    ? (float)$value
                    : (int)$value;
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

    /**
     * Validates URL to prevent SSRF and path traversal attacks
     * Only allows fetching from the same host/port as the configured PowerDNS API
     *
     * @param string $url The URL to validate
     * @return bool True if URL is safe to fetch
     */
    private function isValidMetricsUrl(string $url): bool
    {
        // Validate URL format
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            error_log("PowerdnsStatusService: Invalid URL format");
            return false;
        }

        $parsedUrl = parse_url($url);

        // Ensure scheme is http or https only (prevent file://, ftp://, gopher://, etc.)
        if (!isset($parsedUrl['scheme']) || !in_array($parsedUrl['scheme'], ['http', 'https'], true)) {
            error_log("PowerdnsStatusService: Invalid URL scheme, only http/https allowed");
            return false;
        }

        // Ensure host is present
        if (!isset($parsedUrl['host']) || empty($parsedUrl['host'])) {
            error_log("PowerdnsStatusService: Missing URL host");
            return false;
        }

        // Validate that the URL is from the same host as configured API URL (Option 2)
        if (empty($this->apiUrl)) {
            error_log("PowerdnsStatusService: API URL not configured");
            return false;
        }

        $apiParsedUrl = parse_url($this->apiUrl);
        if (!isset($apiParsedUrl['host'])) {
            error_log("PowerdnsStatusService: Invalid API URL configuration");
            return false;
        }

        // Enforce same host - prevent requests to arbitrary internal services
        if (strtolower($parsedUrl['host']) !== strtolower($apiParsedUrl['host'])) {
            error_log("PowerdnsStatusService: URL host mismatch with configured API URL");
            return false;
        }

        return true;
    }

    /**
     * Sanitize display name and provide fallback to default value
     *
     * @param mixed $displayName The display name from configuration
     * @return string Sanitized display name
     */
    private function sanitizeDisplayName($displayName): string
    {
        // Handle null, false, or non-string values
        if (!is_string($displayName)) {
            return 'PowerDNS';
        }

        // Trim whitespace
        $displayName = trim($displayName);

        // Fallback to default if empty
        if (empty($displayName)) {
            return 'PowerDNS';
        }

        // Limit length to prevent UI issues
        if (strlen($displayName) > 50) {
            $displayName = substr($displayName, 0, 47) . '...';
        }

        return $displayName;
    }

    /**
     * Fetch metrics from URL with optional Basic Authentication
     * Validates URL to prevent SSRF and path traversal attacks
     *
     * @param string $url The metrics URL to fetch
     * @return string|false The metrics content or false on failure
     */
    private function fetchMetricsWithAuth(string $url): string|false
    {
        // Validate URL before fetching to prevent SSRF and path traversal
        if (!$this->isValidMetricsUrl($url)) {
            error_log("PowerdnsStatusService: Blocked unsafe URL fetch attempt");
            return false;
        }

        // If Basic Auth credentials are configured, use stream context
        if (!empty($this->webserverUsername) && !empty($this->webserverPassword)) {
            $auth = base64_encode($this->webserverUsername . ':' . $this->webserverPassword);
            $context = stream_context_create([
                'http' => [
                    'header' => "Authorization: Basic $auth"
                ]
            ]);
            return @file_get_contents($url, false, $context);
        }

        // Fall back to simple file_get_contents without auth
        return @file_get_contents($url);
    }
}
