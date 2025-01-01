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

class DnssecAlgorithmName {
    public const RSAMD5 = 'rsamd5';
    public const DH = 'dh';
    public const DSA = 'dsa';
    public const ECC = 'ecc';
    public const RSASHA1 = 'rsasha1';
    public const RSASHA1_NSEC3 = 'rsasha1-nsec3';
    public const RSASHA256 = 'rsasha256';
    public const RSASHA512 = 'rsasha512';
    public const GOST = 'gost';
    public const ECDSA256 = 'ecdsa256';
    public const ECDSA384 = 'ecdsa384';
    public const ED25519 = 'ed25519';
    public const ED448 = 'ed448';

    public const ALGORITHM_NAMES = [
        self::RSAMD5 => 'RSAMD5',
        self::DH => 'DH',
        self::DSA => 'DSA',
        self::ECC => 'ECC',
        self::RSASHA1 => 'RSASHA1',
        self::RSASHA1_NSEC3 => 'RSASHA1-NSEC3-SHA1',
        self::RSASHA256 => 'RSASHA256',
        self::RSASHA512 => 'RSASHA512',
        self::GOST => 'ECC-GOST',
        self::ECDSA256 => 'ECDSAP256SHA256',
        self::ECDSA384 => 'ECDSAP384SHA384',
        self::ED25519 => 'ED25519',
        self::ED448 => 'ED448',
    ];
}
