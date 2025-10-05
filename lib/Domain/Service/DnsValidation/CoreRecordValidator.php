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
 * Validator for core/fundamental DNS record types
 *
 * Handles validation for:
 * - A (IPv4 Address) - RFC 1035
 * - AAAA (IPv6 Address) - RFC 3596
 * - CNAME (Canonical Name) - RFC 1035
 * - HINFO (Host Information) - RFC 1035
 * - MX (Mail Exchange) - RFC 1035
 * - NS (Name Server) - RFC 1035
 * - PTR (Pointer) - RFC 1035
 * - SOA (Start of Authority) - RFC 1035
 * - TXT (Text) - RFC 1035
 * - MAILA/MAILB (Obsolete meta-query types) - RFC 883
 */
class CoreRecordValidator implements DnsRecordValidatorInterface
{
    /**
     * @inheritDoc
     */
    public function getSupportedTypes(): array
    {
        return ['A', 'AAAA', 'CNAME', 'HINFO', 'MX', 'NS', 'PTR', 'SOA', 'TXT', 'MAILA', 'MAILB'];
    }

    /**
     * @inheritDoc
     */
    public function validate(string $type, string $content, bool $answer = true): bool
    {
        return match ($type) {
            'A' => Dns::is_valid_ipv4($content, $answer),
            'AAAA' => Dns::is_valid_ipv6($content, $answer),
            'HINFO' => Dns::is_valid_rr_hinfo_content($content, $answer),
            'TXT' => Dns::is_valid_printable($content, $answer) && !Dns::has_html_tags($content) && Dns::is_properly_quoted($content),
            'MAILA', 'MAILB' => Dns::is_valid_meta_query_type($type, $answer),
            default => false,
        };
    }
}
