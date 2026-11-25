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

namespace Poweradmin\Infrastructure\Utility;

class CsvFormulaEscaper
{
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    public static function escape(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        // Strip only ASCII spaces - tab/CR/LF must remain to count as direct triggers.
        $trimmed = ltrim($value, ' ');
        if ($trimmed === '') {
            return $value;
        }

        // A leading quote the import would strip needs a guard of its own to survive the round trip.
        if (!in_array($trimmed[0], self::FORMULA_TRIGGERS, true) && !self::hasRemovableGuard($value)) {
            return $value;
        }

        return "'" . $value;
    }

    /**
     * Whether unescape() drops the leading quote: after it come any quotes and spaces, then a formula trigger.
     */
    private static function hasRemovableGuard(string $value): bool
    {
        return $value !== '' && $value[0] === "'" && preg_match('/^[\' ]*[=+\-@\t\r\n]/', substr($value, 1)) === 1;
    }

    /**
     * Undo escape(): drop the single quote it added in front of a formula trigger.
     */
    public static function unescape(mixed $value): mixed
    {
        if (!is_string($value) || !str_starts_with($value, "'")) {
            return $value;
        }

        return self::hasRemovableGuard($value) ? substr($value, 1) : $value;
    }

    public static function escapeRow(array $row): array
    {
        return array_map([self::class, 'escape'], $row);
    }
}
