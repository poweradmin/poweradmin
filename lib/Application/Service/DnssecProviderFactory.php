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

use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Domain\Utility\DnssecDataTransformer;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\CompositeLegacyLogger;
use Poweradmin\Infrastructure\Logger\SyslogLegacyLogger;
use Poweradmin\Infrastructure\Service\DnsSecApiProvider;
use Poweradmin\Infrastructure\Service\PdnsUtilProvider;

class DnssecProviderFactory
{
    public static function create(PDOLayer $db, ConfigurationInterface $config): DnssecProvider
    {
        if (!$config->get('pdns_api_url') || !$config->get('pdns_api_key')) {
            return new PdnsUtilProvider($db);
        }

        $httpClient = new HttpClient($config->get('pdns_api_url'), $config->get('pdns_api_key'));
        $apiClient = new PowerdnsApiClient($httpClient, 'localhost');

        $logger = new CompositeLegacyLogger();

        if ($config->get('syslog_use')) {
            $syslogLogger = new SyslogLegacyLogger(
                $config->get('syslog_ident'),
                $config->get('syslog_facility')
            );
            $logger->addLogger($syslogLogger);
        }

        $transformer = new DnssecDataTransformer();

        return new DnsSecApiProvider(
            $apiClient,
            $logger,
            $transformer,
            $_SERVER['REMOTE_ADDR'],
            $_SESSION['userlogin']
        );
    }
}