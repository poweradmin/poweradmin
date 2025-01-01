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

class DnssecAlgorithm {
    public const RESERVED = 0;
    public const RSAMD5 = 1;
    public const DH = 2;
    public const DSA = 3;
    public const ECC = 4;
    public const RSASHA1 = 5;
    public const DSA_NSEC3_SHA1 = 6;
    public const RSASHA1_NSEC3_SHA1 = 7;
    public const RSASHA256 = 8;
    public const RESERVED_2 = 9;
    public const RSASHA512 = 10;
    public const RESERVED_3 = 11;
    public const ECC_GOST = 12;
    public const ECDSAP256SHA256 = 13;
    public const ECDSAP384SHA384 = 14;
    public const ED25519 = 15;
    public const ED448 = 16;
    public const INDIRECT = 252;
    public const PRIVATEDNS = 253;
    public const PRIVATEOID = 254;

    public const ALGORITHMS = [
        self::RESERVED => 'Reserved',
        self::RSAMD5 => 'RSAMD5',
        self::DH => 'DH',
        self::DSA => 'DSA',
        self::ECC => 'ECC',
        self::RSASHA1 => 'RSASHA1',
        self::DSA_NSEC3_SHA1 => 'DSA-NSEC3-SHA1',
        self::RSASHA1_NSEC3_SHA1 => 'RSASHA1-NSEC3-SHA1',
        self::RSASHA256 => 'RSASHA256',
        self::RESERVED_2 => 'Reserved',
        self::RSASHA512 => 'RSASHA512',
        self::RESERVED_3 => 'Reserved',
        self::ECC_GOST => 'ECC-GOST',
        self::ECDSAP256SHA256 => 'ECDSAP256SHA256',
        self::ECDSAP384SHA384 => 'ECDSAP384SHA384',
        self::ED25519 => 'ED25519',
        self::ED448 => 'ED448',
        self::INDIRECT => 'INDIRECT',
        self::PRIVATEDNS => 'PRIVATEDNS',
        self::PRIVATEOID => 'PRIVATEOID',
    ];
}