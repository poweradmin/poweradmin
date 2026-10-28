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

namespace Poweradmin\Domain\Utility;

/**
 * Domain name helpers: reverse-zone names, domain levels and second-level extraction.
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
        $addr = inet_pton($ip);
        $unpack = unpack('H*hex', $addr);
        $hex = $unpack['hex'];
        return implode('.', array_reverse(str_split($hex))) . '.ip6.arpa';
    }

    /**
     * Convert a full PTR record name back to the address it names
     *
     * @param string $name PTR record name, e.g. 1.2.0.192.in-addr.arpa
     * @return string|null The IPv4 or compressed IPv6 address, or null for a partial, classless or forward name
     */
    public static function convertPtrRecToIpAddr(string $name): ?string
    {
        $name = strtolower(rtrim($name, '.'));

        if (str_ends_with($name, '.ip6.arpa')) {
            return substr_count($name, '.') === 33 ? IpHelper::shortenIPv6ReverseZone($name) : null;
        }

        if (str_ends_with($name, '.in-addr.arpa')) {
            $octets = explode('.', substr($name, 0, -strlen('.in-addr.arpa')));
            $ip = implode('.', array_reverse($octets));

            return count($octets) === 4 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : null;
        }

        return null;
    }

    /**
     * Read an IP address typed as a record name in a reverse zone as the PTR name it stands for
     *
     * A name that is not an address, or a zone that is not a reverse zone, is returned unchanged.
     *
     * @param string $name The submitted record name
     * @param string $zoneName The zone the record is added to
     * @return array{name: string, error: string|null} The name to store, or why the address is refused
     */
    public static function resolveAddressAsRecordName(string $name, string $zoneName): array
    {
        $address = trim($name);
        // A view variant "2.0.192.in-addr.arpa..internal" keeps its records under its apex
        $zone = DnsHelper::zoneApexName($zoneName);
        $lowerZone = strtolower($zone);
        $ip6Zone = $lowerZone === 'ip6.arpa' || str_ends_with($lowerZone, '.ip6.arpa');
        // Only classful zones: under bare in-addr.arpa a dotted quad is also a valid relative name
        $ip4Zone = preg_match('/^(\d{1,3}\.){1,4}in-addr\.arpa$/', $lowerZone) === 1;

        // A colon never appears in a nibble or octet label, so it always means an IPv6 address
        if (($ip6Zone || $ip4Zone) && str_contains($address, ':')) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return ['name' => $name, 'error' => sprintf(_('%s is not a valid IPv6 address.'), $address)];
            }
            $ptr = self::convertIPv6AddrToPtrRec($address);
        } elseif ($ip4Zone && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ptr = self::convertIPv4AddrToPtrRec($address);
        } else {
            return ['name' => $name, 'error' => null];
        }

        if (!DnsHelper::isWithinZone($ptr, $zone)) {
            return ['name' => $name, 'error' => sprintf(_('The address %s is outside the zone %s.'), $address, $zone)];
        }

        // Keep the zone as stored so the name ends in the zone's own spelling
        return ['name' => substr($ptr, 0, strlen($ptr) - strlen($zone)) . $zone, 'error' => null];
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
