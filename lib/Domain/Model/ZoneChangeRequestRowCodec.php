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

use Poweradmin\Domain\Service\Zone\ZoneEditRow;

/**
 * The row shapes stored inside a change request's actions. They are a data
 * contract: requests filed by earlier versions are decoded by this class, so
 * the keys and their types stay as they are.
 */
final class ZoneChangeRequestRowCodec
{
    /**
     * The "after" state of an add or edit action from a normalised editor row.
     *
     * @param array<string, mixed> $row name, type, content, ttl, prio, disabled (0/1) and comment
     * @return array<string, mixed>
     */
    public static function afterState(array $row): array
    {
        return [
            'name' => (string)$row['name'],
            'type' => (string)$row['type'],
            'content' => (string)$row['content'],
            'ttl' => (int)($row['ttl'] ?? 0),
            'prio' => (int)($row['prio'] ?? 0),
            'disabled' => (int)($row['disabled'] ?? 0),
            'comment' => (string)($row['comment'] ?? ''),
        ];
    }

    /**
     * The "before" state of an edit or delete action, in the change log's snapshot shape.
     *
     * @param array<string, mixed> $record The stored row
     * @return array<string, mixed>
     */
    public static function snapshot(array $record, string $zoneName): array
    {
        return [
            'id' => isset($record['id']) ? (string)$record['id'] : null,
            'name' => $record['name'] ?? null,
            'type' => $record['type'] ?? null,
            'content' => $record['content'] ?? null,
            'ttl' => isset($record['ttl']) ? (int)$record['ttl'] : null,
            'prio' => isset($record['prio']) ? (int)$record['prio'] : null,
            'disabled' => isset($record['disabled']) ? (bool)$record['disabled'] : null,
            'comment' => $record['comment'] ?? null,
            'zone_name' => $zoneName,
        ];
    }

    /**
     * A stored "after" state as the editor row to replay against the record it names.
     *
     * @param array<string, mixed> $after
     */
    public static function rowFromAfter(int|string $recordId, array $after): ZoneEditRow
    {
        return new ZoneEditRow(
            $recordId,
            (string)($after['name'] ?? ''),
            (string)($after['type'] ?? ''),
            (string)($after['content'] ?? ''),
            (int)($after['ttl'] ?? 0),
            (int)($after['prio'] ?? 0),
            !empty($after['disabled']),
            (string)($after['comment'] ?? '')
        );
    }
}
