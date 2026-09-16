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

use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Detects and caches the connected PowerDNS server version so admins can see
 * it in the UI and so error reports include it without a manual lookup.
 *
 * Cached in the per-user array the caller hands in (the session, in practice)
 * with a 5-minute TTL to avoid an extra API round-trip on every request.
 * Detection failures are swallowed - callers must treat the absence of a
 * cached value as "version unknown", not as an error.
 */
class PdnsVersionService
{
    private const SESSION_KEY = 'pdns_server_info';
    private const TTL_SECONDS = 300;
    private const RETRY_SECONDS = 60;

    private PowerdnsApiClient $apiClient;
    private LoggerInterface $logger;
    /** @var array<string, mixed> */
    private array $cache;

    /**
     * @param array<string, mixed> $cache Where the server info and retry stamp live, normally $_SESSION
     */
    public function __construct(PowerdnsApiClient $apiClient, ?LoggerInterface $logger = null, array &$cache = [])
    {
        $this->apiClient = $apiClient;
        $this->logger = $logger ?? new NullLogger();
        $this->cache = &$cache;
    }

    /**
     * Fetch server info if the cached copy is stale, then return it.
     *
     * @return array{version: string, daemon_type: string, id: string, backends?: string, views?: string}|null
     */
    public function detect(): ?array
    {
        $cached = $this->cache[self::SESSION_KEY] ?? null;
        if (is_array($cached) && isset($cached['fetched_at']) && (time() - $cached['fetched_at']) < self::TTL_SECONDS) {
            return $cached['info'] ?? null;
        }

        $serverInfo = $this->apiClient->getServerInfo();
        if (empty($serverInfo) || empty($serverInfo['version'])) {
            // Treat empty response as a transient failure - do not cache.
            return null;
        }

        $info = [
            'version' => (string) $serverInfo['version'],
            'daemon_type' => (string) ($serverInfo['daemon_type'] ?? ''),
            'id' => (string) ($serverInfo['id'] ?? ''),
        ];

        // Views need an LMDB backend and views=yes, neither of which the
        // version reveals. Only 5.0+ can have them, so older servers are
        // spared the extra call.
        if (PdnsCapabilities::fromVersion($info['version'])->isAtLeast('5.0.0')) {
            $config = $this->apiClient->getServerConfig();
            $info['backends'] = (string) ($config['launch'] ?? '');
            $info['views'] = (string) ($config['views'] ?? '');
        }

        // Log the version once per session so operational issues can be
        // correlated with a known server version without reading config.
        $previousVersion = is_array($cached) && isset($cached['info']['version']) ? $cached['info']['version'] : null;
        if ($previousVersion !== $info['version']) {
            $this->logger->info('Connected to PowerDNS {version} ({daemon_type})', [
                'version' => $info['version'],
                'daemon_type' => $info['daemon_type'] ?: 'unknown',
            ]);
        }

        $this->cache[self::SESSION_KEY] = [
            'info' => $info,
            'fetched_at' => time(),
        ];

        return $info;
    }

    /**
     * Refresh the cache from the configured PowerDNS API, at most once a minute
     * per cache. A no-op when no API is configured; failures leave the previous
     * entry in place, so callers never see an error from here.
     *
     * @param array<string, mixed> $cache Normally $_SESSION
     */
    public static function refreshFromConfig(ConfigurationInterface $config, LoggerInterface $logger, array &$cache): void
    {
        $apiUrl = (string) $config->get('pdns_api', 'url', '');
        $apiKey = (string) $config->get('pdns_api', 'key', '');
        if ($apiUrl === '' || $apiKey === '') {
            return;
        }
        $last = $cache[SessionKeys::PDNS_VERSION_LAST_ATTEMPT] ?? 0;
        if ((time() - (int) $last) < self::RETRY_SECONDS) {
            return;
        }
        $cache[SessionKeys::PDNS_VERSION_LAST_ATTEMPT] = time();
        try {
            $apiClient = DnsBackendProviderFactory::createApiClient($config, $logger);
            if ($apiClient !== null) {
                (new self($apiClient, $logger, $cache))->detect();
            }
        } catch (\Throwable $e) {
            $logger->debug('PowerDNS version detection failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Return the cached server info without making a network call.
     *
     * @return array{version: string, daemon_type: string, id: string, backends?: string, views?: string}|null
     */
    public function getCached(): ?array
    {
        return self::getCachedInfo($this->cache);
    }

    /**
     * Static accessor for the cached server info, for callers that don't want
     * to construct the service just to read what IndexController already detected.
     *
     * Returns null when the cached entry is older than TTL_SECONDS so that
     * capability-driven UI does not silently follow a stale version after a
     * PowerDNS upgrade or downgrade for the rest of the session - callers
     * that get null fall through to detect() and refresh the cache.
     *
     * @param array<string, mixed> $cache Normally $_SESSION
     * @return array{version: string, daemon_type: string, id: string, backends?: string, views?: string}|null
     */
    public static function getCachedInfo(array $cache): ?array
    {
        $cached = $cache[self::SESSION_KEY] ?? null;
        if (!is_array($cached)) {
            return null;
        }
        $fetchedAt = $cached['fetched_at'] ?? 0;
        if ((time() - (int) $fetchedAt) >= self::TTL_SECONDS) {
            return null;
        }
        $info = $cached['info'] ?? null;
        return is_array($info) ? $info : null;
    }
}
