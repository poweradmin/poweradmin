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

class DnsHelper
{
    private const IPV4_REVERSE_ZONE_PATTERN = '/^(?:[\d\/]+\.){1,4}in-addr\.arpa$/i';
    private const IPV6_REVERSE_ZONE_PATTERN = '/^[0-9a-fA-F\/]+(?:\.[0-9a-fA-F\/]+)*\.ip6\.arpa$/i';

    /**
     * Tell whether a network can be derived from a reverse zone name.
     *
     * This is the strict test: the name must be a structured in-addr.arpa or
     * ip6.arpa name (it powers the reverse-zone form redirect). For plain
     * "is this a reverse zone at all" classification use isReverseZoneName().
     */
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
     * Tell whether a name belongs to the reverse-DNS namespace.
     *
     * This is the broad classification test: any name under in-addr.arpa or
     * ip6.arpa counts, including RFC 2317 classless names (e.g.
     * "0-25.2.0.192.in-addr.arpa") that the strict isReverseZone() rejects
     * because they carry no parsable network. Use this for listing, filtering
     * and "is this a reverse zone at all" decisions; use isReverseZone() only
     * when a network must be derived from the name.
     */
    public static function isReverseZoneName(string $name): bool
    {
        $name = strtolower(rtrim($name, '.'));

        return $name === 'in-addr.arpa'
            || $name === 'ip6.arpa'
            || str_ends_with($name, '.in-addr.arpa')
            || str_ends_with($name, '.ip6.arpa');
    }

    /**
     * Resolve user input on a reverse-zone form to a reverse zone name.
     *
     * Passes an already-valid reverse zone name through unchanged and converts
     * a network (e.g. 192.168.1.0/24, 2001:db8::/48) to its reverse zone name.
     * Returns null when the input is neither, so the caller can reject it
     * instead of silently creating a forward zone.
     *
     * Pass-through is gated on isReverseZone() (not a looser .arpa suffix) so
     * every accepted value is one the post-create redirect and zone lists also
     * classify as reverse. RFC 2317 range-style names fall outside that and are
     * rejected; the user enters the parent zone instead.
     */
    public static function resolveReverseZoneName(string $input): ?string
    {
        if (self::isReverseZone($input)) {
            return $input;
        }

        return IpHelper::networkToReverseZone($input);
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

    /**
     * Strip zone name from record name for display purposes
     *
     * @param string $recordName The full record name (FQDN)
     * @param string $zoneName The zone name to strip
     * @return string The hostname part without zone suffix, or '@' for zone apex
     */
    public static function stripZoneSuffix(string $recordName, string $zoneName): string
    {
        // Remove trailing dot if present (FQDN notation)
        $recordName = rtrim($recordName, '.');
        $zoneName = rtrim($zoneName, '.');

        // If record name equals zone name (case-insensitive), it's the zone apex
        if (strcasecmp($recordName, $zoneName) === 0) {
            return '@';
        }

        // If record name ends with zone name preceded by a dot, strip it (case-insensitive)
        $suffix = '.' . $zoneName;
        $lowerRecordName = strtolower($recordName);
        $lowerSuffix = strtolower($suffix);

        if (str_ends_with($lowerRecordName, $lowerSuffix)) {
            return substr($recordName, 0, -strlen($suffix));
        }

        // Otherwise return as-is (shouldn't happen with valid records)
        return $recordName;
    }

    /**
     * Restore full record name from hostname and zone name
     *
     * @param string $hostname The hostname part (or '@' for zone apex)
     * @param string $zoneName The zone name
     * @return string The full record name (FQDN)
     */
    public static function restoreZoneSuffix(string $hostname, string $zoneName): string
    {
        // Remove trailing dot if present (FQDN notation)
        $hostname = rtrim($hostname, '.');
        $zoneName = rtrim($zoneName, '.');

        // Handle zone apex
        if ($hostname === '@' || $hostname === '') {
            return $zoneName;
        }

        // If hostname already contains zone name (case-insensitive), return as-is
        $lowerHostname = strtolower($hostname);
        $lowerZoneName = strtolower($zoneName);

        if ($lowerHostname === $lowerZoneName || str_ends_with($lowerHostname, '.' . $lowerZoneName)) {
            return $hostname;
        }

        // Otherwise append zone name
        return $hostname . '.' . $zoneName;
    }
}
