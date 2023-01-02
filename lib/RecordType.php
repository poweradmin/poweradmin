<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

namespace Poweradmin;

class RecordType
{
    // The following is a list of supported record types by PowerDNS
    // https://doc.powerdns.com/authoritative/appendices/types.html

    // Array of possible record types
    private const RECORD_TYPES = array(
        'A',
        'A6',
        'AAAA',
        'AFSDB',
        'ALIAS',
        'APL',
        'CAA',
        'CDNSKEY',
        'CDS',
        'CERT',
        'CNAME',
        'CSYNC',
        'DHCID',
        'DLV',
        'DNAME',
        'DNSKEY',
        'DS',
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
        'MX',
        'NAPTR',
        'NID',
        'NS',
        'NSEC',
        'NSEC3',
        'NSEC3PARAM',
        'OPENPGPKEY',
        'PTR',
        'RKEY',
        'RP',
        'RRSIG',
        'SIG',
        'SMIMEA',
        'SOA',
        'SPF',
        'SRV',
        'SSHFP',
        'SVCB',
        'TKEY',
        'TLSA',
        'TSIG',
        'TXT',
        'URI',
        'WKS'
    );

    public static function getTypes() {
        return self::RECORD_TYPES;
    }
}
