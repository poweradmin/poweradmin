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
 * Validator for security and cryptography-related DNS record types
 *
 * Handles validation for:
 * - TLSA (TLS Authentication) - RFC 6698
 * - SSHFP (SSH Fingerprint) - RFC 4255
 * - SMIMEA (S/MIME Certificate Association) - RFC 8162
 * - OPENPGPKEY (OpenPGP Public Key) - RFC 7929
 * - CERT (Certificate) - RFC 4398
 * - DHCID (DHCP Identifier) - RFC 4701
 * - TKEY (Transaction Key) - RFC 2930
 */
class SecurityRecordValidator implements DnsRecordValidatorInterface
{
    /**
     * @inheritDoc
     */
    public function getSupportedTypes(): array
    {
        return ['TLSA', 'SSHFP', 'SMIMEA', 'OPENPGPKEY', 'CERT', 'DHCID', 'TKEY'];
    }

    /**
     * @inheritDoc
     */
    public function validate(string $type, string $content, bool $answer = true): bool
    {
        return match ($type) {
            'TLSA' => Dns::is_valid_tlsa($content, $answer),
            'SSHFP' => Dns::is_valid_sshfp($content, $answer),
            'SMIMEA' => Dns::is_valid_smimea($content, $answer),
            'OPENPGPKEY' => Dns::is_valid_openpgpkey($content, $answer),
            'CERT' => Dns::is_valid_cert($content, $answer),
            'DHCID' => Dns::is_valid_dhcid($content, $answer),
            'TKEY' => Dns::is_valid_tkey($content, $answer),
            default => false,
        };
    }
}
