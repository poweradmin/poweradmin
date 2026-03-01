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
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;

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
     * @param PDOCommon $db Database connection
     * @param ConfigurationInterface $config Configuration object
     * @return DnsBackendProvider
     */
    public static function create(PDOCommon $db, ConfigurationInterface $config): DnsBackendProvider
    {
        $backend = $config->get('pdns_api', 'backend');
        $pdnsApiUrl = $config->get('pdns_api', 'url');
        $pdnsApiKey = $config->get('pdns_api', 'key');

        if ($backend === 'api' && $pdnsApiUrl && $pdnsApiKey) {
            $httpClient = new HttpClient($pdnsApiUrl, $pdnsApiKey);
            $serverNameFromConfig = $config->get('pdns_api', 'server_name');
            $serverName = $serverNameFromConfig ?: 'localhost';
            $apiClient = new PowerdnsApiClient($httpClient, $serverName);

            return new ApiDnsBackendProvider($apiClient, $db, $config);
        }

        if ($backend === 'api' && (!$pdnsApiUrl || !$pdnsApiKey)) {
            error_log('Poweradmin: pdns_api.backend is set to "api" but url/key are not configured. Falling back to SQL backend.');
        }

        return new SqlDnsBackendProvider($db, $config);
    }
}
