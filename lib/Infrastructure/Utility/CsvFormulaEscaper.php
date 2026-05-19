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
        if ($trimmed === '' || !in_array($trimmed[0], self::FORMULA_TRIGGERS, true)) {
            return $value;
        }

        return "'" . $value;
    }

    public static function escapeRow(array $row): array
    {
        return array_map([self::class, 'escape'], $row);
    }
}
