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

namespace Poweradmin\Application\Web;

use Poweradmin\Domain\Service\Zone\ZoneEditRow;
use Poweradmin\Domain\Service\Zone\ZoneEditSubmission;

/**
 * Decodes the zone editor's POST: the record[] rows with their trailing
 * _complete marker, the checkbox encoding of "disabled", the form_complete
 * marker at the end of the form, and the fields around the table.
 */
final class ZoneEditFormParser
{
    /**
     * @param array<string, mixed> $post The POST fields as submitted
     */
    public static function fromPost(array $post, int $zoneId, string $zoneName, int $userId, string $username): ZoneEditSubmission
    {
        $records = is_array($post['record'] ?? null) ? $post['record'] : null;
        $formComplete = ($post['form_complete'] ?? null) !== null;
        // The form ends with form_complete and each row with _complete: when
        // max_input_vars truncates the POST those trailing fields go first
        $truncated = $records !== null && !$formComplete;

        $rows = [];
        foreach ($records ?? [] as $key => $record) {
            if (!is_array($record) || !isset($record['_complete'])) {
                $truncated = true;
                continue;
            }
            $rows[] = self::row($record, $key);
        }

        $serial = $post['serial'] ?? null;
        $zoneComment = $post['zone_comment'] ?? null;

        return new ZoneEditSubmission(
            $zoneId,
            $zoneName,
            $userId,
            $username,
            $rows,
            $truncated,
            $serial === null ? null : (string)$serial,
            ($post['changed_rows_only'] ?? null) === '1',
            $zoneComment === null ? null : (string)$zoneComment
        );
    }

    /**
     * @param array<string, mixed> $record One complete posted row
     */
    private static function row(array $record, int|string $key): ZoneEditRow
    {
        return new ZoneEditRow(
            (string)($record['rid'] ?? $key),
            (string)($record['name'] ?? ''),
            (string)($record['type'] ?? ''),
            (string)($record['content'] ?? ''),
            (int)($record['ttl'] ?? 0),
            (int)($record['prio'] ?? 0),
            isset($record['disabled']) && $record['disabled'] == 'on',
            isset($record['comment']) ? (string)$record['comment'] : null
        );
    }
}
