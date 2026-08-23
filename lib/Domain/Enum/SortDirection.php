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

namespace Poweradmin\Domain\Enum;

/**
 * SQL sort direction.
 *
 * These values are interpolated straight into ORDER BY clauses, so every entry
 * point must normalise through here rather than trusting the caller's string.
 */
enum SortDirection: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';

    /**
     * Normalise arbitrary input, falling back to ASC.
     *
     * Accepts any casing; anything unrecognised (including null) becomes ASC so
     * a malformed request degrades to the default ordering instead of reaching SQL.
     */
    public static function fromRequest(?string $value, self $default = self::ASC): self
    {
        if ($value === null) {
            return $default;
        }

        return self::tryFrom(strtoupper(trim($value))) ?? $default;
    }

    /**
     * Whether a string is already a valid direction, without normalising case.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
