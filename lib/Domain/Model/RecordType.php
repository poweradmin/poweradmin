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

namespace Poweradmin\Domain\Model;

class RecordType
{
    // The following is a list of supported record types by PowerDNS
    // https://doc.powerdns.com/authoritative/appendices/types.html

    // Common record types for domain zones
    private const DOMAIN_ZONE_COMMON_RECORDS = [
        'A',
        'AAAA',
        'CNAME',
        'MX',
        'NS',
        'SOA',
        'SRV',
        'TXT',
    ];

    // Common record types for reverse zones
    private const REVERSE_ZONE_COMMON_RECORDS = [
        'CNAME',
        'LOC',
        'NS',
        'PTR',
        'SOA',
        'TXT',
    ];

    // DNSSEC-related record types
    private const DNSSEC_TYPES = [
        'CDNSKEY',
        'CDS',
        'DNSKEY',
        'DS',
        'NSEC',
        'NSEC3',
        'NSEC3PARAM',
        'RRSIG',
        'ZONEMD',
    ];

    // Less common but valid records
    private const LESS_COMMON_RECORDS = [
        'A6',
        'AFSDB',
        'ALIAS',
        'APL',
        'CAA',
        'CERT',
        'CSYNC',
        'DHCID',
        'DLV',
        'DNAME',
        'EUI48',
        'EUI64',
        'HINFO',
        'HTTPS',
        'IPSECKEY',
        'KEY',
        'KX',
        'L32',
        'L64',
        'LUA',
        'LOC',
        'LP',
        'MAILA',
        'MAILB',
        'MINFO',
        'MR',
        'NAPTR',
        'NID',
        'OPENPGPKEY',
        'RKEY',
        'RP',
        'SIG',
        'SMIMEA',
        'SPF',
        'SSHFP',
        'SVCB',
        'TKEY',
        'TLSA',
        'TSIG',
        'URI',
        'WKS',
    ];

    // Private constructor to prevent instantiation
    private function __construct()
    {
    }

    /**
     * Get all record types.
     *
     * @return array
     */
    public static function getAllTypes(): array
    {
        $types = array_merge(
            self::DOMAIN_ZONE_COMMON_RECORDS,
            self::REVERSE_ZONE_COMMON_RECORDS,
            self::DNSSEC_TYPES,
            self::LESS_COMMON_RECORDS
        );
        sort($types);
        return $types;
    }

    /**
     * Get domain zone record types.
     *
     * @param bool $isDnsSecEnabled
     * @return array
     */
    public static function getDomainZoneTypes(bool $isDnsSecEnabled): array
    {
        $types = array_merge(self::DOMAIN_ZONE_COMMON_RECORDS, self::LESS_COMMON_RECORDS);
        return self::mergeDnsSecTypes($types, $isDnsSecEnabled);
    }

    /**
     * Get reverse zone record types.
     *
     * @param bool $isDnsSecEnabled
     * @return array
     */
    public static function getReverseZoneTypes(bool $isDnsSecEnabled): array
    {
        $types = self::REVERSE_ZONE_COMMON_RECORDS;
        return self::mergeDnsSecTypes($types, $isDnsSecEnabled);
    }

    /**
     * Merge DNSSEC types if enabled.
     *
     * @param array $types
     * @param bool $isDnsSecEnabled
     * @return array
     */
    private static function mergeDnsSecTypes(array $types, bool $isDnsSecEnabled): array
    {
        if ($isDnsSecEnabled) {
            $types = array_merge($types, self::DNSSEC_TYPES);
        }
        sort($types);
        return $types;
    }
}
