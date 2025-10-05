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
 * Validator for special-purpose DNS record types
 *
 * Handles validation for:
 * - LOC (Location Information) - RFC 1876
 * - SPF (Sender Policy Framework, deprecated) - RFC 7208
 * - CAA (Certification Authority Authorization) - RFC 8659
 * - CSYNC (Child-to-Parent Synchronization) - RFC 7477
 * - ZONEMD (Zone Message Digest) - RFC 8976
 */
class SpecialRecordValidator implements DnsRecordValidatorInterface
{
    /**
     * @inheritDoc
     */
    public function getSupportedTypes(): array
    {
        return ['LOC', 'SPF', 'CAA', 'CSYNC', 'ZONEMD'];
    }

    /**
     * @inheritDoc
     */
    public function validate(string $type, string $content, bool $answer = true): bool
    {
        return match ($type) {
            'LOC' => Dns::is_valid_loc($content),
            'SPF' => Dns::is_valid_spf($content, $answer),
            'CAA' => Dns::is_valid_caa($content, $answer),
            'CSYNC' => Dns::is_valid_csync($content, $answer),
            'ZONEMD' => Dns::is_valid_zonemd($content, $answer),
            default => false,
        };
    }
}
