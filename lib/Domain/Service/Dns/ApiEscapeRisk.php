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

namespace Poweradmin\Domain\Service\Dns;

/**
 * Finds records that PowerDNS Auth 5.1.x would alter when they are written back
 * through its REST API: it removes one level of backslash escaping from record
 * content (PowerDNS issue 18159), so "a\\b" is stored as "a\b" and "a\"b" is refused.
 */
final class ApiEscapeRisk
{
    public static function affects(string $content): bool
    {
        return str_contains($content, '\\');
    }

    /**
     * Distinct "name TYPE" labels of the RRsets holding at least one affected record.
     *
     * @param iterable<array{name?: string, type?: string, content?: string}> $records
     * @return list<string>
     */
    public static function affectedRrsets(iterable $records): array
    {
        $labels = [];
        foreach ($records as $record) {
            if (self::affects((string)($record['content'] ?? ''))) {
                $labels[($record['name'] ?? '') . ' ' . ($record['type'] ?? '')] = true;
            }
        }

        return array_keys($labels);
    }
}
