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

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Service for building absolute URLs
 *
 * This service provides consistent URL building across the application,
 * supporting both configured base URLs and auto-detection from HTTP headers.
 */
class UrlService
{
    private ConfigurationInterface $config;

    public function __construct(ConfigurationInterface $config)
    {
        $this->config = $config;
    }

    /**
     * Build an absolute URL from a relative path
     *
     * Priority order for base URL:
     * 1. Configured application_url (if set)
     * 2. Auto-detected from HTTP_HOST and HTTPS headers
     *
     * @param string $path Relative path (e.g., '/zones/123/edit')
     * @return string Full absolute URL (e.g., 'https://example.com/poweradmin/zones/123/edit')
     */
    public function getAbsoluteUrl(string $path): string
    {
        $baseUrl = $this->getBaseUrl();
        $path = ltrim($path, '/');

        return rtrim($baseUrl, '/') . '/' . $path;
    }

    /**
     * Get the base URL for the application
     *
     * Returns the full base URL including protocol, host, and base path prefix.
     * Uses configured URL if available, otherwise auto-detects from server variables.
     *
     * @return string Base URL (e.g., 'https://example.com/poweradmin')
     */
    public function getBaseUrl(): string
    {
        // Check if application_url is explicitly configured
        $configuredUrl = $this->config->get('interface', 'application_url', '');
        if (!empty($configuredUrl)) {
            return rtrim($configuredUrl, '/');
        }

        // Auto-detect from server variables
        return $this->buildBaseUrlFromServer();
    }

    /**
     * Build base URL from server variables
     *
     * Auto-detects protocol, host, and base path prefix from server environment.
     *
     * @return string Base URL built from server variables
     */
    private function buildBaseUrlFromServer(): string
    {
        $protocol = $this->getProtocol();
        $host = $this->getHost();
        $basePath = $this->getBasePath();

        return "$protocol://$host$basePath";
    }

    /**
     * Detect protocol (http or https)
     *
     * @return string 'https' or 'http'
     */
    private function getProtocol(): string
    {
        // Check HTTPS server variable
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }

        // Check X-Forwarded-Proto header (for reverse proxies)
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return 'https';
        }

        // Check if running on standard HTTPS port
        if (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443') {
            return 'https';
        }

        return 'http';
    }

    /**
     * Get host from server variables with validation
     *
     * Validates HTTP_HOST against configured application_url to prevent
     * host header injection attacks that could lead to phishing emails.
     *
     * @return string Host (e.g., 'example.com' or 'example.com:8080')
     */
    private function getHost(): string
    {
        $host = '';

        // Prefer HTTP_HOST as it includes port if non-standard
        if (!empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        } elseif (!empty($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];

            // Add port if non-standard
            $port = $_SERVER['SERVER_PORT'] ?? '80';
            if (($port != '80' && $port != '443')) {
                $host .= ':' . $port;
            }
        } else {
            // Ultimate fallback
            return 'localhost';
        }

        // Validate host against configured application_url to prevent host header injection
        $configuredUrl = $this->config->get('interface', 'application_url', '');
        if (!empty($configuredUrl)) {
            $expectedHost = $this->extractHostFromUrl($configuredUrl);

            // If configured URL exists, validate the detected host matches
            if (!empty($expectedHost) && strcasecmp($host, $expectedHost) !== 0) {
                // Log suspicious activity - possible host header injection attempt
                error_log("UrlService: Host header mismatch - got '$host', expected '$expectedHost' from configuration. Using configured host for security.");
                return $expectedHost;
            }
        }

        return $host;
    }

    /**
     * Extract host (with port if present) from a URL
     *
     * @param string $url Full URL
     * @return string Host with port (e.g., 'example.com:8080') or empty string on failure
     */
    private function extractHostFromUrl(string $url): string
    {
        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['host'])) {
            return '';
        }

        $host = $parsedUrl['host'];

        // Add port if present and non-standard
        if (isset($parsedUrl['port'])) {
            $port = $parsedUrl['port'];
            $scheme = $parsedUrl['scheme'] ?? 'http';

            // Only add port if it's not the default for the scheme
            if (($scheme === 'http' && $port != 80) || ($scheme === 'https' && $port != 443)) {
                $host .= ':' . $port;
            }
        }

        return $host;
    }

    /**
     * Get configured base path prefix
     *
     * Falls back to auto-detection from SCRIPT_NAME if base_url_prefix is not configured,
     * but only in web contexts (not CLI).
     *
     * @return string Base path (e.g., '/poweradmin' or '')
     */
    private function getBasePath(): string
    {
        $basePath = $this->config->get('interface', 'base_url_prefix', '');

        // If base_url_prefix is explicitly configured, use it
        if (!empty($basePath)) {
            return rtrim($basePath, '/');
        }

        // Fall back to auto-detection from SCRIPT_NAME, but only in web contexts
        // In CLI contexts (PHPUnit, cron jobs, queue workers), SCRIPT_NAME might be
        // something like 'bin/console' which would produce incorrect 'bin' prefix
        if (php_sapi_name() !== 'cli' && !empty($_SERVER['SCRIPT_NAME'])) {
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            // Only use if it's not the root directory and looks like a valid web path
            if ($scriptDir !== '/' && $scriptDir !== '\\' && !str_contains($scriptDir, 'bin')) {
                return rtrim($scriptDir, '/');
            }
        }

        return '';
    }

    /**
     * Get the login page URL
     *
     * Convenience method for building login URL.
     *
     * @return string Full URL to login page
     */
    public function getLoginUrl(): string
    {
        return $this->getAbsoluteUrl('/login');
    }

    /**
     * Get a zone edit URL
     *
     * Convenience method for building zone edit URL.
     *
     * @param int $zoneId Zone ID
     * @return string Full URL to zone edit page
     */
    public function getZoneEditUrl(int $zoneId): string
    {
        return $this->getAbsoluteUrl("/zones/$zoneId/edit");
    }
}
