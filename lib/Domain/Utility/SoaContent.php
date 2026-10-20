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

namespace Poweradmin\Domain\Utility;

/**
 * Splits stored SOA content into its seven named fields for display.
 */
final class SoaContent
{
    /** @var list<string> */
    public const FIELD_KEYS = ['primary_ns', 'hostmaster', 'serial', 'refresh', 'retry', 'expire', 'minimum'];

    /**
     * @return array<string, string>|null Field key to value, or null when the content is not exactly seven fields
     */
    public static function parse(string $content): ?array
    {
        $parts = preg_split('/\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) !== count(self::FIELD_KEYS)) {
            return null;
        }

        return array_combine(self::FIELD_KEYS, $parts);
    }

    /**
     * Compact duration in the largest whole units, e.g. 10800 is "3h" and 90000 is "1d 1h".
     * assets/textareaAutoResize.js (soaDuration) must produce the same output.
     *
     * @return string|null Null when the value is not a plain non-negative integer
     */
    public static function duration(string $seconds): ?string
    {
        if (!ctype_digit($seconds) || strlen($seconds) > 12) {
            return null;
        }

        $remaining = (int)$seconds;
        if ($remaining === 0) {
            return '0s';
        }

        $parts = [];
        foreach (['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $unit => $size) {
            if ($remaining >= $size) {
                $parts[] = intdiv($remaining, $size) . $unit;
                $remaining %= $size;
            }
        }

        return implode(' ', $parts);
    }
}
