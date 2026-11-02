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

use Exception;
use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Domain\Utility\DnssecDataTransformer;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use PDO;
use Psr\Log\LoggerInterface;
use Poweradmin\Infrastructure\Logger\CompositeLegacyLogger;
use Poweradmin\Infrastructure\Logger\SyslogLegacyLogger;
use Poweradmin\Infrastructure\Service\DnsSecApiProvider;
use Poweradmin\Infrastructure\Service\NullDnssecProvider;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

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
        if (empty($version)) {
            return false;
        }

        // Remove any non-numeric prefixes if present
        $version = preg_replace('/^[^0-9]*/', '', $version);

        // Compare versions - PowerDNS 4.0.0 and higher use CSK by default
        return version_compare($version, '4.0.0', '>=');
    }

    /**
     * Create DNSSEC provider instance using PowerDNS API
     *
     * @param PDO $db Database connection
     * @param ConfigurationInterface $config Configuration object
     * @return DnssecProvider DNSSEC provider instance
     * @throws Exception When PowerDNS API is not configured
     */
    public static function create(PDO $db, ConfigurationInterface $config): DnssecProvider
    {
        $pdnsApiUrl = $config->get('pdns_api', 'url');
        $pdnsApiKey = $config->get('pdns_api', 'key');

        if (!$pdnsApiUrl || !$pdnsApiKey) {
            return new NullDnssecProvider();
        }

        $httpClient = new HttpClient($pdnsApiUrl, $pdnsApiKey);

        // Get the server name, with a default if not found
        $serverNameFromConfig = $config->get('pdns_api', 'server_name');
        $serverName = $serverNameFromConfig ?: 'localhost';

        $apiClient = new PowerdnsApiClient($httpClient, $serverName);

        $logger = new CompositeLegacyLogger();

        // Check for syslog being enabled
        $syslogEnabled = $config->get('logging', 'syslog_enabled');
        if ($syslogEnabled) {
            // Get syslog identity and facility with defaults
            $syslogIdentity = $config->get('logging', 'syslog_identity');
            $syslogIdentity = $syslogIdentity ?: 'poweradmin';

            $syslogFacility = $config->get('logging', 'syslog_facility');
            $syslogFacility = $syslogFacility ?: LOG_USER;

            $syslogLogger = new SyslogLegacyLogger($syslogIdentity, $syslogFacility);
            $logger->addLogger($syslogLogger);
        }

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

    /** A rectify failure this slow means PowerDNS timed out or stalled, not that it refused one zone. */
    private const RECTIFY_SLOW_FAILURE_SECONDS = 3.0;

    private static bool $rectifyStalled = false;

    /**
     * Rectify a zone after its records were written, so a signed zone's NSEC/NSEC3
     * chain follows the change. The write is already committed: failures are logged.
     * After one slow failure the rest of the request skips rectify, so a bulk template
     * update cannot wait the API timeout once per zone; a slow success never trips it.
     */
    public static function rectifyAfterWrite(
        PDO $db,
        ConfigurationInterface $config,
        string $zoneName,
        ?LoggerInterface $logger = null
    ): void {
        if (!$config->get('dnssec', 'enabled')) {
            return;
        }
        if (self::$rectifyStalled) {
            $logger?->warning('Zone rectify skipped for {zone}: an earlier rectify in this request failed after timing out or stalling', ['zone' => $zoneName]);
            return;
        }
        $started = hrtime(true);
        try {
            if (self::create($db, $config)->rectifyZone($zoneName)) {
                return;
            }
            $logger?->warning('Zone rectify refused for {zone}', ['zone' => $zoneName]);
        } catch (\Throwable $e) {
            $logger?->warning('Zone rectify failed for {zone}: {error}', ['zone' => $zoneName, 'error' => $e->getMessage()]);
        }
        self::$rectifyStalled = (hrtime(true) - $started) / 1e9 >= self::RECTIFY_SLOW_FAILURE_SECONDS;
    }
}
