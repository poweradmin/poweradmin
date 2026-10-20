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

namespace Poweradmin\Application\Controller\Api\V2\Resource;

use Poweradmin\Domain\Utility\DnsHelper;

/**
 * The JSON shape of a record on /api/v2. The list endpoints report the fully
 * qualified name, the single-record endpoints the name relative to the zone;
 * both are contract and neither may drift without the other being reviewed.
 */
final class RecordResource
{
    /**
     * A single record as GET, POST and PUT /zones/{id}/records emit it: the name
     * relative to the zone, the zone id second. A row without an id (the readback
     * after a write failed) reports null.
     *
     * @param array<string, mixed> $row
     * @param callable(int|string): (int|string) $formatId
     * @return array<string, mixed>
     */
    public static function item(array $row, int $zoneId, string $zoneName, callable $formatId): array
    {
        return [
            'id' => isset($row['id']) ? $formatId($row['id']) : null,
            'zone_id' => $zoneId,
            'name' => DnsHelper::stripZoneSuffix((string)$row['name'], $zoneName),
        ] + self::fields($row);
    }

    /**
     * A record as the listing emits it: fully qualified name, no zone id.
     *
     * @param array<string, mixed> $row
     * @param callable(int|string): (int|string) $formatId
     * @return array<string, mixed>
     */
    public static function listItem(array $row, callable $formatId): array
    {
        return ['id' => $formatId($row['id']), 'name' => $row['name']] + self::fields($row);
    }

    /**
     * A member of an RRSet: the per-record values without name, type or ttl.
     *
     * @param array<string, mixed> $row
     * @return array{content: string, priority: int, disabled: bool}
     */
    public static function rrsetMember(array $row): array
    {
        return [
            'content' => self::stripTxtQuotes((string)($row['content'] ?? ''), (string)($row['type'] ?? '')),
            'priority' => isset($row['prio']) ? (int)$row['prio'] : 0,
            'disabled' => self::disabled($row),
        ];
    }

    /**
     * V2 quotes single-string TXT content on the way in and unquotes it on the way
     * out; a multi-string value keeps its quoting so the parts stay distinct.
     */
    public static function stripTxtQuotes(string $content, string $type): string
    {
        if ($type !== 'TXT') {
            return $content;
        }

        $content = trim($content);
        $isMultiString = str_contains($content, '" "');

        if (!$isMultiString && str_starts_with($content, '"') && str_ends_with($content, '"') && strlen($content) > 1) {
            return substr($content, 1, -1);
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function fields(array $row): array
    {
        return [
            'type' => $row['type'],
            'content' => self::stripTxtQuotes((string)$row['content'], (string)$row['type']),
            'ttl' => (int)$row['ttl'],
            'priority' => isset($row['prio']) ? (int)$row['prio'] : 0,
            'disabled' => self::disabled($row),
            'auth' => isset($row['auth']) ? (bool)$row['auth'] : true,
        ];
    }

    /**
     * The repositories decode the driver's flag encoding, so a plain cast is enough here.
     *
     * @param array<string, mixed> $row
     */
    private static function disabled(array $row): bool
    {
        return !empty($row['disabled']);
    }
}
