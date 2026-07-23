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

namespace Poweradmin\Application\Service;

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Utility\ProtocolDetector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for building absolute URLs
 *
 * This service provides consistent URL building across the application,
 * supporting both configured base URLs and auto-detection from HTTP headers.
 */
class UrlService
{
    private ConfigurationInterface $config;
    private LoggerInterface $logger;

    public function __construct(ConfigurationInterface $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
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
     * Build an absolute URL for use inside outbound emails
     *
     * Only uses the configured interface.application_url. HTTP_HOST and other
     * request-time headers are intentionally ignored so that an emailed link
     * cannot be redirected by a forged Host header.
     *
     * @param string $path Relative path (e.g., '/password/reset?token=...')
     * @return string|null Full absolute URL, or null if application_url is not configured
     */
    public function getEmailUrl(string $path): ?string
    {
        $configuredUrl = $this->config->get('interface', 'application_url', '');
        if (empty($configuredUrl)) {
            $this->logger->warning('UrlService: refusing to build email URL because interface.application_url is not configured');
            return null;
        }

        return rtrim($configuredUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Build an absolute URL for emails, falling back to SERVER_NAME when application_url is unset
     *
     * Suitable for non-secret-bearing emails (e.g., username recovery). HTTP_HOST is
     * never consulted. SERVER_PORT is intentionally not appended - it's the backend
     * port, not the public-facing one, so deployments on non-standard public ports
     * must set interface.application_url to get correct links.
     *
     * @param string $path Relative path
     * @return string|null Absolute URL, or null if neither application_url nor SERVER_NAME is available
     */
    public function getEmailUrlWithServerFallback(string $path): ?string
    {
        $configuredUrl = $this->config->get('interface', 'application_url', '');
        if (!empty($configuredUrl)) {
            return rtrim($configuredUrl, '/') . '/' . ltrim($path, '/');
        }

        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        if ($serverName === '') {
            $this->logger->warning('UrlService: refusing to build email URL - interface.application_url and SERVER_NAME are both unset');
            return null;
        }

        return $this->getProtocol() . '://' . $serverName . $this->getBasePath() . '/' . ltrim($path, '/');
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
        $protocolDetector = new ProtocolDetector();
        return $protocolDetector->detect();
    }

    /**
     * Get host from server variables
     *
     * Prefers the configured application_url. HTTP_HOST is attacker-controllable, so it is
     * only consulted when application_url is set (and then validated against it); otherwise
     * the host is taken from SERVER_NAME, matching the OIDC/SAML/logout link-building paths.
     *
     * @return string Host (e.g., 'example.com' or 'example.com:8080')
     */
    private function getHost(): string
    {
        $configuredUrl = $this->config->get('interface', 'application_url', '');

        // Only trust HTTP_HOST when application_url is configured, because the mismatch check
        // below then forces the configured host. Without it, fall back to SERVER_NAME.
        if (!empty($configuredUrl) && !empty($_SERVER['HTTP_HOST'])) {
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

        // When application_url is configured, force it on any host mismatch
        if (!empty($configuredUrl)) {
            $expectedHost = $this->extractHostFromUrl($configuredUrl);

            if (!empty($expectedHost) && strcasecmp($host, $expectedHost) !== 0) {
                $this->logger->warning('UrlService: Host header mismatch - got {host}, expected {expectedHost} from configuration. Using configured host.', ['host' => $host, 'expectedHost' => $expectedHost]);
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
