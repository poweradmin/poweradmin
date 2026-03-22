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

use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use PDO;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creating DNS backend provider instances.
 *
 * Selects between SQL (direct database) and API (PowerDNS REST API)
 * backends based on configuration. Follows the same pattern as
 * DnssecProviderFactory.
 */
class DnsBackendProviderFactory
{
    /**
     * Create a DNS backend provider instance.
     *
     * @param PDO $db Database connection
     * @param ConfigurationInterface $config Configuration object
     * @param LoggerInterface|null $logger PSR-3 logger
     * @return DnsBackendProvider
     */
    public static function create(PDO $db, ConfigurationInterface $config, ?LoggerInterface $logger = null): DnsBackendProvider
    {
        $logger = $logger ?? new NullLogger();
        $backend = $config->get('dns', 'backend');
        $pdnsApiUrl = $config->get('pdns_api', 'url');
        $pdnsApiKey = $config->get('pdns_api', 'key');

        if ($backend === 'api' && $pdnsApiUrl && $pdnsApiKey) {
            $httpClient = new HttpClient($pdnsApiUrl, $pdnsApiKey, $logger);
            $serverNameFromConfig = $config->get('pdns_api', 'server_name');
            $serverName = $serverNameFromConfig ?: 'localhost';
            $apiClient = new PowerdnsApiClient($httpClient, $serverName, $logger);

            return new ApiDnsBackendProvider($apiClient, $db, $config, $logger);
        }

        if ($backend === 'api' && (!$pdnsApiUrl || !$pdnsApiKey)) {
            $logger->warning('dns.backend is set to "api" but pdns_api url/key are not configured. Falling back to SQL backend.');
        }

        return new SqlDnsBackendProvider($db, $config, $logger);
    }

    /**
     * Check whether the resolved backend is API mode.
     *
     * Mirrors the create() logic: returns true only when dns.backend is 'api'
     * AND the API URL/key are configured. When credentials are missing, the
     * factory falls back to SQL, so this returns false.
     */
    public static function isApiBackend(ConfigurationInterface $config): bool
    {
        return $config->get('dns', 'backend') === 'api'
            && $config->get('pdns_api', 'url')
            && $config->get('pdns_api', 'key');
    }

    public static function createApiClient(ConfigurationInterface $config, ?LoggerInterface $logger = null): ?PowerdnsApiClient
    {
        $pdnsApiUrl = $config->get('pdns_api', 'url');
        $pdnsApiKey = $config->get('pdns_api', 'key');

        if (!$pdnsApiUrl || !$pdnsApiKey) {
            return null;
        }

        $logger = $logger ?? new NullLogger();
        $httpClient = new HttpClient($pdnsApiUrl, $pdnsApiKey, $logger);
        $serverName = $config->get('pdns_api', 'server_name') ?: 'localhost';

        return new PowerdnsApiClient($httpClient, $serverName, $logger);
    }
}
