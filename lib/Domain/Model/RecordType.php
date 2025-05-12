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

/**
 * Class containing constants for DNS record types.
 */
class RecordType
{
    // The following is a list of supported record types by PowerDNS
    // https://doc.powerdns.com/authoritative/appendices/types.html

    // Individual record type constants
    public const A = 'A';
    public const AAAA = 'AAAA';
    public const AFSDB = 'AFSDB';
    public const ALIAS = 'ALIAS';
    public const APL = 'APL';
    public const CAA = 'CAA';
    public const CERT = 'CERT';
    public const CDNSKEY = 'CDNSKEY';
    public const CDS = 'CDS';
    public const CNAME = 'CNAME';
    public const CSYNC = 'CSYNC';
    public const DHCID = 'DHCID';
    public const DNSKEY = 'DNSKEY';
    public const DNAME = 'DNAME';
    public const DS = 'DS';
    public const DLV = 'DLV';
    public const DMARC = 'DMARC';
    public const EUI48 = 'EUI48';
    public const EUI64 = 'EUI64';
    public const HINFO = 'HINFO';
    public const HTTPS = 'HTTPS';
    public const IPSECKEY = 'IPSECKEY';
    public const KEY = 'KEY';
    public const KX = 'KX';
    public const L32 = 'L32';
    public const L64 = 'L64';
    public const LOC = 'LOC';
    public const LP = 'LP';
    public const LUA = 'LUA';
    public const MINFO = 'MINFO';
    public const MR = 'MR';
    public const MX = 'MX';
    public const NAPTR = 'NAPTR';
    public const NID = 'NID';
    public const NS = 'NS';
    public const NSEC = 'NSEC';
    public const NSEC3 = 'NSEC3';
    public const NSEC3PARAM = 'NSEC3PARAM';
    public const OPENPGPKEY = 'OPENPGPKEY';
    public const PTR = 'PTR';
    public const RKEY = 'RKEY';
    public const RP = 'RP';
    public const RRSIG = 'RRSIG';
    public const SMIMEA = 'SMIMEA';
    public const SOA = 'SOA';
    public const SPF = 'SPF';
    public const SRV = 'SRV';
    public const SSHFP = 'SSHFP';
    public const SVCB = 'SVCB';
    public const TKEY = 'TKEY';
    public const TLSA = 'TLSA';
    public const TSIG = 'TSIG';
    public const TXT = 'TXT';
    public const URI = 'URI';
    public const ZONEMD = 'ZONEMD';

    // Common record types for domain zones
    public const DOMAIN_ZONE_COMMON_RECORDS = [
        self::A,
        self::AAAA,
        self::CNAME,
        self::MX,
        self::NS,
        self::SOA,
        self::SRV,
        self::TXT,
    ];

    // Common record types for reverse zones
    public const REVERSE_ZONE_COMMON_RECORDS = [
        self::CNAME,
        self::LOC,
        self::NS,
        self::PTR,
        self::SOA,
        self::TXT,
    ];

    // DNSSEC-related record types
    public const DNSSEC_TYPES = [
        self::CDNSKEY,
        self::CDS,
        self::DNSKEY,
        self::DS,
        self::NSEC,
        self::NSEC3,
        self::NSEC3PARAM,
        self::RRSIG,
        self::ZONEMD,
    ];

    // Less common but valid records
    public const LESS_COMMON_RECORDS = [
        self::AFSDB,
        self::ALIAS,
        self::APL,
        self::CAA,
        self::CERT,
        self::CSYNC,
        self::DHCID,
        self::DLV,
        self::DMARC,
        self::DNAME,
        self::EUI48,
        self::EUI64,
        self::HINFO,
        self::HTTPS,
        self::IPSECKEY,
        self::KEY,
        self::KX,
        self::L32,
        self::L64,
        self::LUA,
        self::LOC,
        self::LP,
        self::MINFO,
        self::MR,
        self::NAPTR,
        self::NID,
        self::OPENPGPKEY,
        self::RKEY,
        self::RP,
        self::SMIMEA,
        self::SPF,
        self::SSHFP,
        self::SVCB,
        self::TKEY,
        self::TLSA,
        self::TSIG,
        self::URI,
    ];

    // Private constructor to prevent instantiation
    private function __construct()
    {
    }
}
