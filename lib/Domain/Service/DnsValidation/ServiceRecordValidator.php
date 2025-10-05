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
 * Validator for service discovery and URI-related DNS record types
 *
 * Handles validation for:
 * - SRV (Service Locator) - RFC 2782
 * - NAPTR (Naming Authority Pointer) - RFC 3403
 * - URI (Uniform Resource Identifier) - RFC 7553
 * - SVCB (Service Binding) - RFC 9460
 * - HTTPS (HTTPS Service Binding) - RFC 9460
 */
class ServiceRecordValidator implements DnsRecordValidatorInterface
{
    /**
     * @inheritDoc
     */
    public function getSupportedTypes(): array
    {
        return ['SRV', 'NAPTR', 'URI', 'SVCB', 'HTTPS'];
    }

    /**
     * @inheritDoc
     */
    public function validate(string $type, string $content, bool $answer = true): bool
    {
        return match ($type) {
            'NAPTR' => Dns::is_valid_naptr($content, $answer),
            'URI' => Dns::is_valid_uri($content, $answer),
            'SVCB' => Dns::is_valid_svcb($content, $answer),
            'HTTPS' => Dns::is_valid_https($content, $answer),
            default => false,
        };
    }
}
