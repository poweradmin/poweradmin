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

namespace Poweradmin\Domain\Model;

/**
 * The first-letter filter on the forward zone list: '1' for a leading digit, a-z or '_', 'idn' for
 * zones whose name starts with a punycode label, or 'all'.
 */
final class ZoneStartingLetter
{
    public const ALL = 'all';
    public const DIGITS = '1';
    public const IDN = 'idn';
    public const PUNYCODE_PREFIX = 'xn--';

    /**
     * Any other value (a stale non-ASCII letter kept in the session, for example) shows all zones.
     */
    public static function normalize(?string $letter): string
    {
        $letter = strtolower((string)$letter);
        if ($letter === self::DIGITS || $letter === self::IDN || preg_match('/^[a-z_]$/', $letter) === 1) {
            return $letter;
        }
        return self::ALL;
    }

    public static function startsWithPunycode(string $name): bool
    {
        return str_starts_with(strtolower($name), self::PUNYCODE_PREFIX);
    }

    /**
     * Whether a zone name falls under the filter. A punycode name is listed only under 'idn',
     * not under 'x'.
     */
    public static function matches(string $name, string $letter): bool
    {
        if ($letter === self::ALL) {
            return true;
        }
        if ($name === '') {
            return false;
        }
        if ($letter === self::IDN) {
            return self::startsWithPunycode($name);
        }
        if (self::startsWithPunycode($name)) {
            return false;
        }
        if ($letter === self::DIGITS) {
            return ctype_digit($name[0]);
        }
        return strtolower($name[0]) === strtolower($letter);
    }
}
