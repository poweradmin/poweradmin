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

use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Detects and caches the connected PowerDNS server version so admins can see
 * it in the UI and so error reports include it without a manual lookup.
 *
 * Cached in the session with a 5-minute TTL to avoid an extra API round-trip
 * on every request. Detection failures are swallowed - callers must treat the
 * absence of a cached value as "version unknown", not as an error.
 */
class PdnsVersionService
{
    private const SESSION_KEY = 'pdns_server_info';
    private const TTL_SECONDS = 300;

    private PowerdnsApiClient $apiClient;
    private LoggerInterface $logger;

    public function __construct(PowerdnsApiClient $apiClient, ?LoggerInterface $logger = null)
    {
        $this->apiClient = $apiClient;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Fetch server info if the cached copy is stale, then return it.
     *
     * @return array{version: string, daemon_type: string, id: string}|null
     */
    public function detect(): ?array
    {
        $cached = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($cached) && isset($cached['fetched_at']) && (time() - $cached['fetched_at']) < self::TTL_SECONDS) {
            return $cached['info'] ?? null;
        }

        $serverInfo = $this->apiClient->getServerInfo();
        if (empty($serverInfo) || empty($serverInfo['version'])) {
            // Treat empty response as a transient failure - do not cache.
            return null;
        }

        $info = [
            'version' => (string) ($serverInfo['version'] ?? ''),
            'daemon_type' => (string) ($serverInfo['daemon_type'] ?? ''),
            'id' => (string) ($serverInfo['id'] ?? ''),
        ];

        // Log the version once per session so operational issues can be
        // correlated with a known server version without reading config.
        $previousVersion = is_array($cached) && isset($cached['info']['version']) ? $cached['info']['version'] : null;
        if ($previousVersion !== $info['version']) {
            $this->logger->info('Connected to PowerDNS {version} ({daemon_type})', [
                'version' => $info['version'],
                'daemon_type' => $info['daemon_type'] ?: 'unknown',
            ]);
        }

        $_SESSION[self::SESSION_KEY] = [
            'info' => $info,
            'fetched_at' => time(),
        ];

        return $info;
    }

    /**
     * Return the cached server info without making a network call.
     *
     * @return array{version: string, daemon_type: string, id: string}|null
     */
    public function getCached(): ?array
    {
        return self::getCachedInfo();
    }

    /**
     * Static accessor for the session-cached server info. Useful for callers
     * (e.g. BaseController) that don't want to construct the service just to
     * read what IndexController already detected.
     *
     * @return array{version: string, daemon_type: string, id: string}|null
     */
    public static function getCachedInfo(): ?array
    {
        $cached = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($cached)) {
            return null;
        }
        $info = $cached['info'] ?? null;
        return is_array($info) ? $info : null;
    }
}
