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

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Domain\Service\Dns;

/**
 * Validator for network and addressing-related DNS record types
 *
 * Handles validation for:
 * - EUI48 (MAC-48 Address) - RFC 7043
 * - EUI64 (EUI-64 Address) - RFC 7043
 * - NID (Node Identifier) - RFC 6742
 * - L32 (Locator32) - RFC 6742
 * - L64 (Locator64) - RFC 6742
 * - LP (Locator Pointer) - RFC 6742
 * - IPSECKEY (IPsec Key) - RFC 4025
 * - WKS (Well-Known Services) - RFC 1035
 * - KX (Key Exchanger) - RFC 2230
 */
class NetworkRecordValidator implements DnsRecordValidatorInterface
{
    /**
     * @inheritDoc
     */
    public function getSupportedTypes(): array
    {
        return ['EUI48', 'EUI64', 'NID', 'L32', 'L64', 'LP', 'IPSECKEY', 'WKS', 'KX'];
    }

    /**
     * @inheritDoc
     */
    public function validate(string $type, string $content, bool $answer = true): bool
    {
        return match ($type) {
            'EUI48' => Dns::is_valid_eui48($content, $answer),
            'EUI64' => Dns::is_valid_eui64($content, $answer),
            'NID' => Dns::is_valid_nid($content, $answer),
            'L32' => Dns::is_valid_l32($content, $answer),
            'L64' => Dns::is_valid_l64($content, $answer),
            'LP' => Dns::is_valid_lp($content, $answer),
            'IPSECKEY' => Dns::is_valid_ipseckey($content, $answer),
            'WKS' => Dns::is_valid_wks($content, $answer),
            'KX' => Dns::is_valid_kx($content, $answer),
            default => false,
        };
    }
}
