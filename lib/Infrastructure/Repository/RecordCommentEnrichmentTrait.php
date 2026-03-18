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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Infrastructure\Database\PdnsTable;

/**
 * Shared comment enrichment logic for record repositories.
 *
 * Both SQL and API record repositories need to enrich records with
 * comments from Poweradmin tables. This trait extracts that shared logic.
 *
 * @property PDO $db
 * @property \Poweradmin\Infrastructure\Database\TableNameService $tableNameService
 */
trait RecordCommentEnrichmentTrait
{
    /**
     * Enrich records with comments from Poweradmin DB.
     * Uses a two-pass approach: first per-record linked comments, then
     * RRset-level comments as fallback for records with no linked comment.
     */
    private function enrichRecordsWithComments(array &$records): void
    {
        if (empty($records)) {
            return;
        }

        // Pass 1: set per-record linked comments
        $this->enrichRecordsWithLinkedComments($records);
        // Pass 2: fill remaining nulls with RRset-level comments
        $this->enrichRecordsWithRRsetComments($records);
    }

    private function enrichRecordsWithLinkedComments(array &$records): void
    {
        $recordIds = array_filter(
            array_map(fn($r) => $r['id'] ?? null, $records),
            fn($id) => $id !== null && $id !== 0
        );
        if (empty($recordIds)) {
            return;
        }

        // Convert all IDs to strings for VARCHAR column comparison
        $recordIds = array_map('strval', $recordIds);

        $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
        $comments_table = $this->tableNameService->getTable(PdnsTable::COMMENTS);

        $stmt = $this->db->prepare(
            "SELECT rcl.record_id, c.comment
             FROM record_comment_links rcl
             INNER JOIN $comments_table c ON rcl.comment_id = c.id
             WHERE rcl.record_id IN ($placeholders)"
        );
        $stmt->execute(array_values($recordIds));

        $comments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $comments[$row['record_id']] = $row['comment'];
        }

        foreach ($records as &$record) {
            $rid = $record['id'] ?? null;
            if ($rid !== null) {
                $record['comment'] = $comments[(string)$rid] ?? null;
            }
        }
        unset($record);
    }

    private function enrichRecordsWithRRsetComments(array &$records): void
    {
        $comments_table = $this->tableNameService->getTable(PdnsTable::COMMENTS);

        // Collect unique domain_id + name + type combinations
        $keys = [];
        foreach ($records as $record) {
            $key = ($record['domain_id'] ?? '') . '|' . ($record['name'] ?? '') . '|' . ($record['type'] ?? '');
            $keys[$key] = true;
        }

        if (empty($keys)) {
            return;
        }

        // Build query for RRset-level comments
        $conditions = [];
        $params = [];
        $i = 0;
        foreach (array_keys($keys) as $key) {
            [$domainId, $name, $type] = explode('|', $key);
            if ($domainId === '' || $name === '' || $type === '') {
                continue;
            }
            $conditions[] = "(domain_id = :did{$i} AND name = :name{$i} AND type = :type{$i})";
            $params[":did{$i}"] = (int)$domainId;
            $params[":name{$i}"] = $name;
            $params[":type{$i}"] = $type;
            $i++;
        }

        if (empty($conditions)) {
            foreach ($records as &$record) {
                $record['comment'] = null;
            }
            unset($record);
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT domain_id, name, type, comment
             FROM $comments_table c
             WHERE (" . implode(' OR ', $conditions) . ")
               AND NOT EXISTS (
                   SELECT 1 FROM record_comment_links rcl
                   WHERE rcl.comment_id = c.id
               )"
        );
        $stmt->execute($params);

        $comments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['domain_id'] . '|' . $row['name'] . '|' . $row['type'];
            $comments[$key] = $row['comment'];
        }

        foreach ($records as &$record) {
            $key = ($record['domain_id'] ?? '') . '|' . ($record['name'] ?? '') . '|' . ($record['type'] ?? '');
            // Only fill if no linked comment was found in the first pass
            if (!isset($record['comment']) || $record['comment'] === null) {
                $record['comment'] = $comments[$key] ?? null;
            }
        }
        unset($record);
    }
}
