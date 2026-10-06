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

namespace Poweradmin\Application\Service\Zone;

use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;

/**
 * Reads a submitted zone limit: empty clears the own limit (the configured default
 * applies), a whole number of 0 or more sets it.
 */
final class ZoneLimitInput
{
    /**
     * From a web form field.
     *
     * @return array{valid: bool, limit: ?int}
     */
    public static function fromForm(mixed $value): array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return ['valid' => true, 'limit' => null];
        }
        if (!is_string($value) || !ctype_digit(trim($value)) || strlen(ltrim(trim($value), '0')) > 10) {
            return ['valid' => false, 'limit' => null];
        }

        return self::fromJson((int)trim($value));
    }

    /**
     * From a JSON body: null or a non-negative integer.
     *
     * @return array{valid: bool, limit: ?int}
     */
    public static function fromJson(mixed $value): array
    {
        if ($value === null) {
            return ['valid' => true, 'limit' => null];
        }

        return is_int($value) && $value >= 0 && $value <= ZoneOwnershipLimit::MAX_LIMIT
            ? ['valid' => true, 'limit' => $value]
            : ['valid' => false, 'limit' => null];
    }
}
