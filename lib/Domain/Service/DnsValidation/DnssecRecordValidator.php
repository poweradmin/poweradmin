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
 * Validator for DNSSEC-related DNS record types
 *
 * Handles validation for:
 * - DNSKEY (DNS Public Key) - RFC 4034
 * - DS (Delegation Signer) - RFC 4034
 * - NSEC (Next Secure) - RFC 4034
 * - NSEC3 (Next Secure v3) - RFC 5155
 * - NSEC3PARAM (NSEC3 Parameters) - RFC 5155
 * - RRSIG (Resource Record Signature) - RFC 4034
 * - TSIG (Transaction Signature) - RFC 2845
 * - DLV (DNSSEC Lookaside Validation) - RFC 4431
 * - KEY (Public Key, deprecated) - RFC 2535
 */
class DnssecRecordValidator implements DnsRecordValidatorInterface
{
    /**
     * @inheritDoc
     */
    public function getSupportedTypes(): array
    {
        return ['DNSKEY', 'DS', 'NSEC', 'NSEC3', 'NSEC3PARAM', 'RRSIG', 'TSIG', 'DLV', 'KEY'];
    }

    /**
     * @inheritDoc
     */
    public function validate(string $type, string $content, bool $answer = true): bool
    {
        return match ($type) {
            'DNSKEY' => Dns::is_valid_dnskey($content, $answer),
            'DS' => Dns::is_valid_ds($content),
            'NSEC' => Dns::is_valid_nsec($content, $answer),
            'NSEC3' => Dns::is_valid_nsec3($content, $answer),
            'NSEC3PARAM' => Dns::is_valid_nsec3param($content, $answer),
            'RRSIG' => Dns::is_valid_rrsig($content, $answer),
            'TSIG' => Dns::is_valid_tsig($content, $answer),
            'DLV' => Dns::is_valid_dlv($content, $answer),
            'KEY' => Dns::is_valid_key($content, $answer),
            default => false,
        };
    }
}
