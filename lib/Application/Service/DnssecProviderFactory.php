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
use Poweradmin\Domain\Service\DnssecProviderInterface;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Domain\Utility\DnssecDataTransformer;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use PDO;
use Poweradmin\Infrastructure\Logger\SyslogLogger;
use Psr\Log\NullLogger;
use Poweradmin\Infrastructure\Service\DnsSecApiProvider;
use Poweradmin\Infrastructure\Service\NullDnssecProvider;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

/**
 * Builds the DNSSEC provider for the configured PowerDNS API, or a null provider when the API is not set up.
 */
class DnssecProviderFactory
{
    /**
     * Get the PowerDNS version if available
     *
     * @param ConfigurationInterface $config Configuration object
     * @return string The PowerDNS version or empty string if not available
     */
    public static function getPowerDnsVersion(ConfigurationInterface $config): string
    {
        $pdnsApiUrl = $config->get('pdns_api', 'url');
        $pdnsApiKey = $config->get('pdns_api', 'key');

        if (!$pdnsApiUrl || !$pdnsApiKey) {
            return '';
        }

        $httpClient = new HttpClient($pdnsApiUrl, $pdnsApiKey);
        $serverNameFromConfig = $config->get('pdns_api', 'server_name');
        $serverName = $serverNameFromConfig ?: 'localhost';

        $apiClient = new PowerdnsApiClient($httpClient, $serverName);

        try {
            $serverInfo = $apiClient->getServerInfo();
            return $serverInfo['version'] ?? '';
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Check if PowerDNS version supports CSK by default
     *
     * @param string $version PowerDNS version string
     * @return bool True if version is 4.0.0 or higher
     */
    public static function supportsDefaultCsk(string $version): bool
    {
        return PdnsCapabilities::fromVersion($version)->supportsDefaultCsk();
    }

    /**
     * Create DNSSEC provider instance using PowerDNS API
     *
     * @param PDO $db Database connection
     * @param ConfigurationInterface $config Configuration object
     * @param PowerdnsApiClient|null $apiClient Reuse a caller's client and its
     *        per-request caches; one is built from config when omitted
     * @return DnssecProviderInterface DNSSEC provider instance
     * @throws Exception When PowerDNS API is not configured
     */
    public static function create(PDO $db, ConfigurationInterface $config, ?PowerdnsApiClient $apiClient = null): DnssecProviderInterface
    {
        $pdnsApiUrl = $config->get('pdns_api', 'url');
        $pdnsApiKey = $config->get('pdns_api', 'key');

        if (!$pdnsApiUrl || !$pdnsApiKey) {
            return new NullDnssecProvider();
        }

        if ($apiClient === null) {
            $httpClient = new HttpClient($pdnsApiUrl, $pdnsApiKey);

            // Get the server name, with a default if not found
            $serverNameFromConfig = $config->get('pdns_api', 'server_name');
            $serverName = $serverNameFromConfig ?: 'localhost';

            $apiClient = new PowerdnsApiClient($httpClient, $serverName);
        }

        // DNSSEC operations are audited to syslog only; without it they are not recorded.
        $logger = $config->get('logging', 'syslog_enabled')
            ? new SyslogLogger(
                $config->get('logging', 'syslog_identity') ?: 'poweradmin',
                (int)($config->get('logging', 'syslog_facility') ?: LOG_USER)
            )
            : new NullLogger();

        $transformer = new DnssecDataTransformer();
        $userContextService = new UserContextService();
        $ipRetriever = new IpAddressRetriever($_SERVER);

        return new DnsSecApiProvider(
            $apiClient,
            $logger,
            $transformer,
            $ipRetriever->getClientIp() ?: 'unknown',
            $userContextService->getLoggedInUsername() ?? 'api_user_' . ($userContextService->getLoggedInUserId() ?? 'unknown')
        );
    }
}
