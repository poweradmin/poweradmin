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
     * 2. A localhost base built from the detected protocol and base path prefix
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
     * Build base URL without a configured application_url
     *
     * Auto-detects protocol and base path prefix from the server environment; the host
     * is fixed because no request-derived host can be trusted.
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
     * Get the host used for auto-detected base URLs
     *
     * Only reached when interface.application_url is unset, and no request-derived host is
     * trustworthy then, so a fixed host is returned.
     *
     * @return string Host
     */
    private function getHost(): string
    {
        // Neither HTTP_HOST nor SERVER_NAME may reach an emitted URL: SERVER_NAME follows
        // the client Host header under FrankenPHP and Apache defaults.
        return 'localhost';
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
     * Get a zone edit URL for use inside outbound emails
     *
     * @param int $zoneId Zone ID
     * @return string|null Full URL to zone edit page, or null if application_url is not configured
     */
    public function getZoneEditUrl(int $zoneId): ?string
    {
        return $this->getEmailUrl("/zones/$zoneId/edit");
    }
}
