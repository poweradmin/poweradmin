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

namespace Poweradmin\Application\Presenter;

use Poweradmin\Domain\Model\ZoneChangeRequest;

/**
 * Shapes change requests for the list, review and edit-page templates.
 */
class ChangeRequestPresenter
{
    private const FIELDS = ['name', 'type', 'content', 'ttl', 'prio', 'disabled', 'comment'];

    /**
     * One row of a request listing.
     *
     * @return array<string, mixed>
     */
    public static function summary(ZoneChangeRequest $request, ?int $now = null): array
    {
        return [
            'id' => $request->id,
            'zone_id' => $request->zoneId,
            'zone_name' => $request->zoneName,
            'kind' => $request->kind,
            'status' => $request->status,
            'requester_id' => $request->requesterId,
            'requester_name' => $request->requesterName,
            'request_comment' => $request->requestComment,
            'reviewer_name' => $request->reviewerName,
            'review_comment' => $request->reviewComment,
            'created_at' => $request->createdAt,
            'reviewed_at' => $request->reviewedAt,
            'applied_at' => $request->appliedAt,
            'error' => $request->error,
            'age' => self::age($request->createdAt, $now),
            'action_count' => $request->actionCount(),
            'is_pending' => $request->isPending(),
        ];
    }

    /**
     * @param list<ZoneChangeRequest> $requests
     * @return list<array<string, mixed>>
     */
    public static function summaries(array $requests, ?int $now = null): array
    {
        return array_map(static fn(ZoneChangeRequest $request): array => self::summary($request, $now), $requests);
    }

    /**
     * The request's actions as before/after rows with the differing fields named.
     *
     * @param list<int> $staleIndexes Actions the zone has moved away from
     * @return list<array{op: string, before: array<string, mixed>|null, after: array<string, mixed>|null, changed: list<string>, stale: bool}>
     */
    public static function actions(ZoneChangeRequest $request, array $staleIndexes): array
    {
        $rows = [];
        foreach ($request->actions as $index => $action) {
            $before = is_array($action['before'] ?? null) ? $action['before'] : null;
            $after = is_array($action['after'] ?? null) ? $action['after'] : null;
            $rows[] = [
                'op' => (string)($action['op'] ?? ''),
                'before' => $before,
                'after' => $after,
                'changed' => self::changedFields($before, $after),
                'stale' => in_array($index, $staleIndexes, true),
            ];
        }

        return $rows;
    }

    /**
     * Fields whose value differs between the two snapshots. The stored "before"
     * carries booleans and the posted "after" integers, so values compare as text.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return list<string>
     */
    public static function changedFields(?array $before, ?array $after): array
    {
        if ($before === null || $after === null) {
            return [];
        }

        $changed = [];
        foreach (self::FIELDS as $field) {
            if (self::normalize($field, $before[$field] ?? null) !== self::normalize($field, $after[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Rough age of a timestamp in words, for listings where the exact time is a tooltip.
     */
    public static function age(string $createdAt, ?int $now = null): string
    {
        $created = strtotime($createdAt);
        if ($created === false) {
            return $createdAt;
        }
        $seconds = max(0, ($now ?? time()) - $created);

        if ($seconds < 60) {
            return _('just now');
        }
        if ($seconds < 3600) {
            return sprintf(_('%d min ago'), intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return sprintf(_('%d h ago'), intdiv($seconds, 3600));
        }

        return sprintf(_('%d d ago'), intdiv($seconds, 86400));
    }

    private static function normalize(string $field, mixed $value): string
    {
        if ($field === 'disabled') {
            return $value ? '1' : '0';
        }
        if ($field === 'ttl' || $field === 'prio') {
            return (string)(int)$value;
        }

        return (string)($value ?? '');
    }
}
