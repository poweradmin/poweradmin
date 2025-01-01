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
 *
 */

namespace Poweradmin\Domain\Utility;

use Pdp\CannotProcessHost;
use Pdp\Rules;
use Pdp\Domain;
use Pdp\TopLevelDomains;

class DnsHelper
{
    private const IPV4_REVERSE_ZONE_PATTERN = '/^(?:\d+\.){1,4}in-addr\.arpa$/i';
    private const IPV6_REVERSE_ZONE_PATTERN = '/^(?:[0-9a-fA-F]+\.){1,32}ip6\.arpa$/i';

    public static function isReverseZone(string $zoneName): bool
    {
        if (preg_match(self::IPV4_REVERSE_ZONE_PATTERN, $zoneName) === 1) {
            return true;
        }

        if (preg_match(self::IPV6_REVERSE_ZONE_PATTERN, $zoneName) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @throws CannotProcessHost
     */
    public static function getRegisteredDomain(string $domain): string
    {
        $rules = Rules::fromPath(__DIR__ . '/../../../data/public_suffix_list.dat');

        $domain = Domain::fromIDNA2008($domain);
        $result = $rules->resolve($domain);

        return $result->registrableDomain()->toString();
    }

    public static function getSubDomainName(string $domain): string
    {
        $domainParts = explode('.', $domain);
        $domainPartsCount = count($domainParts);

        if ($domainPartsCount <= 2) {
            return $domain;
        }

        $domainNameParts = array_slice($domainParts, 0, $domainPartsCount - 2);
        return implode('.', $domainNameParts);
    }
}
