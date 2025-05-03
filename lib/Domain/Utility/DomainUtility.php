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

namespace Poweradmin\Domain\Utility;

use Poweradmin\Domain\Utility\NetworkUtility;

/**
 * Utility class for domain operations
 */
class DomainUtility
{
    /**
     * Convert IPv4 Address to PTR Record
     *
     * @param string $ip IPv4 Address
     * @return string PTR form of address
     */
    public static function convertIPv4AddrToPtrRec(string $ip): string
    {
        $ip_octets = explode('.', $ip);
        return implode('.', array_reverse($ip_octets)) . '.in-addr.arpa';
    }

    /**
     * Convert IPv6 Address to PTR Record
     *
     * @param string $ip IPv6 Address
     * @return string PTR form of address
     */
    public static function convertIPv6AddrToPtrRec(string $ip): string
    {
        // Taken from: http://stackoverflow.com/questions/6619682/convert-ipv6-to-nibble-format-for-ptr-records
        $addr = NetworkUtility::inetPton($ip);
        $unpack = unpack('H*hex', $addr);
        $hex = $unpack['hex'];
        return implode('.', array_reverse(str_split($hex))) . '.ip6.arpa';
    }

    /**
     * Return domain level for given name
     *
     * @param string $name Zone name
     *
     * @return int domain level
     */
    public static function getDomainLevel(string $name): int
    {
        return substr_count($name, '.') + 1;
    }

    /**
     * Return domain second level domain for given name
     *
     * @param string $name Zone name
     *
     * @return string 2nd level domain name
     */
    public static function getSecondLevelDomain(string $name): string
    {
        $domain_parts = explode('.', $name);
        $domain_parts = array_reverse($domain_parts);
        return $domain_parts[1] . '.' . $domain_parts[0];
    }
}
