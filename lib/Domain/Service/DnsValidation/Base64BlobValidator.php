<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * Validator for opaque RDATA stored as base64 in zone-file form (PowerDNS xfrBlob).
 *
 * Whitespace inside the encoded value is permitted (zone files often line-wrap
 * long blobs) and stripped before decoding. Reuses across record types whose
 * RDATA is an arbitrary binary blob: HHIT, BRID, etc.
 *
 * On success returns the whitespace-stripped, normalised encoding so callers
 * store exactly what was validated (not the raw input which may contain
 * control characters that survive the regex check).
 */
final class Base64BlobValidator
{
    // DNS RDATA is capped at 65535 bytes per RR; the base64 encoding of that
    // is ceil(65535 / 3) * 4 = 87380 chars (plus up to 2 padding chars).
    private const MAX_ENCODED_LENGTH = 87382;

    public static function validate(string $content, string $failureMessage): ValidationResult
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return ValidationResult::failure($failureMessage);
        }

        $compact = preg_replace('/\s+/', '', $trimmed) ?? '';
        if ($compact === '' || strlen($compact) > self::MAX_ENCODED_LENGTH) {
            return ValidationResult::failure($failureMessage);
        }
        if (!preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $compact)) {
            return ValidationResult::failure($failureMessage);
        }

        // strict=true rejects any character outside the base64 alphabet, which
        // we already filtered above; the check here catches length mismatches.
        if (base64_decode($compact, true) === false) {
            return ValidationResult::failure($failureMessage);
        }

        return ValidationResult::success(['compact' => $compact]);
    }

    private function __construct()
    {
    }
}
