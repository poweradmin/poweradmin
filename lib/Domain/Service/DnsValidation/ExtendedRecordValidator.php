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
 * Validator for extended and less common DNS record types
 *
 * Handles validation for:
 * - AFSDB (AFS Database) - RFC 1183
 * - APL (Address Prefix List) - RFC 3123
 * - CDNSKEY (Child DNSKEY) - RFC 7344
 * - CDS (Child DS) - RFC 7344
 * - CERT (Certificate) - RFC 4398
 * - DNAME (Delegation Name) - RFC 6672
 * - LUA (Lua Script) - PowerDNS specific
 * - ALIAS (Auto-resolved Alias) - PowerDNS specific
 * - A6 (IPv6 Address, deprecated) - RFC 2874
 * - SIG (Signature, deprecated) - RFC 2535
 */
class ExtendedRecordValidator implements DnsRecordValidatorInterface
{
    /**
     * @inheritDoc
     */
    public function getSupportedTypes(): array
    {
        return ['AFSDB', 'APL', 'CDNSKEY', 'CDS', 'DNAME', 'LUA', 'ALIAS', 'A6', 'SIG'];
    }

    /**
     * @inheritDoc
     */
    public function validate(string $type, string $content, bool $answer = true): bool
    {
        return match ($type) {
            'AFSDB' => Dns::is_valid_afsdb($content, $answer),
            'APL' => Dns::is_valid_apl($content, $answer),
            'CDNSKEY' => Dns::is_valid_cdnskey($content, $answer),
            'CDS' => Dns::is_valid_cds($content, $answer),
            'DNAME' => Dns::is_valid_dname($content, $answer),
            'LUA' => Dns::is_valid_lua($content, $answer),
            'ALIAS' => Dns::is_valid_alias($content, $answer),
            'A6' => Dns::is_valid_a6($content, $answer),
            'SIG' => Dns::is_valid_sig($content, $answer),
            default => false,
        };
    }
}
