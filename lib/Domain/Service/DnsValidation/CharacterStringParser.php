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

/**
 * Parser for DNS <character-string> wire format (RFC 1035 section 3.3).
 *
 * A character-string is a sequence of up to 255 octets, optionally enclosed in
 * double quotes. PowerDNS uses xfrText for record types whose RDATA is one or
 * more concatenated character-strings: TXT, SPF, RESINFO, WALLET, etc.
 */
final class CharacterStringParser
{
    private const MAX_STRING_LENGTH = 255;

    /**
     * Split content into individual character-strings.
     *
     * Returns an array of the unquoted string parts on success, or null if the
     * content is not a valid sequence of quoted character-strings (e.g. missing
     * quotes, unescaped inner quote, individual string longer than 255 bytes).
     *
     * @return array<int, string>|null
     */
    public static function parse(string $content): ?array
    {
        $content = trim($content);
        if ($content === '' || $content[0] !== '"') {
            return null;
        }

        if (!preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"(?:\s+|$)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // Matches must be contiguous and cover the whole input - otherwise the
        // regex has silently skipped unparseable text between strings (e.g.
        // `"a" garbage "b"` or `"a""b"`).
        $expected = 0;
        $parts = [];
        foreach ($matches[0] as $i => $match) {
            if ($match[1] !== $expected) {
                return null;
            }
            $unquoted = $matches[1][$i][0];
            if (strlen($unquoted) > self::MAX_STRING_LENGTH) {
                return null;
            }
            $parts[] = $unquoted;
            $expected = $match[1] + strlen($match[0]);
        }
        if ($expected !== strlen($content)) {
            return null;
        }

        return $parts;
    }

    private function __construct()
    {
    }
}
