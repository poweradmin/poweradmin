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

namespace Poweradmin\Domain\ValueObject;

/**
 * Encoded composite record identifier for API-mode records.
 *
 * In API mode, PowerDNS records have no numeric database IDs.
 * Records are identified by their composite key (zone_name, name, type, content, prio).
 * This class encodes/decodes that composite key as a URL-safe base64 string
 * that can be used transparently wherever integer record IDs are used in SQL mode.
 */
class RecordIdentifier
{
    public static function encode(string $zoneName, string $name, string $type, string $content, int $prio): string
    {
        $data = json_encode([
            'z' => $zoneName,
            'n' => $name,
            't' => $type,
            'c' => $content,
            'p' => $prio,
        ], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return array{zone_name: string, name: string, type: string, content: string, prio: int}
     */
    public static function decode(string $encoded): array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'));
        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['z'], $data['n'], $data['t'], $data['c'])) {
            throw new \InvalidArgumentException("Invalid encoded record identifier: $encoded");
        }

        return [
            'zone_name' => $data['z'],
            'name' => $data['n'],
            'type' => $data['t'],
            'content' => $data['c'],
            'prio' => (int)($data['p'] ?? 0),
        ];
    }

    public static function isEncoded(int|string $id): bool
    {
        if (is_int($id)) {
            return false;
        }

        return !ctype_digit($id) && strlen($id) > 10;
    }
}
