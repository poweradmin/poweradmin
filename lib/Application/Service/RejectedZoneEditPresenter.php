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

namespace Poweradmin\Application\Service;

/**
 * Puts a zone-editor submission that was refused as stale back into the freshly
 * read record listing, so the warning does not also cost the operator their edits.
 */
class RejectedZoneEditPresenter
{
    /**
     * Put a rejected submission back into the rendered rows, so warning the operator
     * that their form was stale does not also cost them their edits.
     *
     * @param array $displayRecords rows read back from the zone
     * @param array<int|string, mixed> $rejectedRecords the rows as they were posted
     * @return array{records: array, dropped: string[]} the rows with the submission put
     *   back, and the submitted rows the listing no longer holds
     */
    public static function restore(array $displayRecords, array $rejectedRecords): array
    {
        if ($rejectedRecords === []) {
            return ['records' => $displayRecords, 'dropped' => []];
        }

        $positions = [];
        foreach ($displayRecords as $index => $displayRecord) {
            $positions[(string)$displayRecord['id']] = $index;
        }

        $dropped = [];
        foreach ($rejectedRecords as $key => $submitted) {
            // Rows without the marker arrived truncated by max_input_vars. Restoring one
            // would merge half a submission into the stored row and hide that it lost
            // fields, so it stays as the zone has it.
            if (!is_array($submitted) || !isset($submitted['_complete'])) {
                continue;
            }
            $rid = (string)($submitted['rid'] ?? $key);

            // A row can leave the listing by being deleted or by no longer matching an
            // active filter. Either way it has nowhere to go back to, so report it.
            if (!isset($positions[$rid])) {
                $dropped[] = self::describeDroppedRow($submitted);
                continue;
            }

            $index = $positions[$rid];
            $row = $displayRecords[$index];

            // The other writer turned this into something the user may not edit. Such a
            // row renders read-only, so it has to keep the values the zone holds.
            if (!empty($row['record_locked'])) {
                $dropped[] = self::describeDroppedRow($submitted);
                continue;
            }

            // A row matching the zone has nothing to restore. This is what keeps a
            // submit with JavaScript off, which posts every row, from forcing rows the
            // operator never touched into the retry.
            $summary = self::describeStoredValues($row, $submitted);
            if ($summary === '') {
                continue;
            }

            $row['stored_summary'] = $summary;
            $row['editable_name'] = $submitted['name'] ?? $row['editable_name'];
            // The type is a hidden field here, so this is the type the row was edited
            // against. Keeping the stored one would retry the edits against a type the
            // operator never saw, which for a changed type is a different record.
            $row['type'] = $submitted['type'] ?? $row['type'];
            $row['content'] = $submitted['content'] ?? $row['content'];
            $row['prio'] = $submitted['prio'] ?? $row['prio'];
            $row['ttl'] = $submitted['ttl'] ?? $row['ttl'];
            $row['comment'] = $submitted['comment'] ?? $row['comment'];
            $row['disabled'] = isset($submitted['disabled']) && $submitted['disabled'] === 'on' ? 1 : 0;
            $row['unsaved_edit'] = true;
            $displayRecords[$index] = $row;
        }

        return ['records' => $displayRecords, 'dropped' => $dropped];
    }

    /**
     * A row that cannot be put back, as one line, so the operator still sees every field
     * they typed rather than only enough to recognise the record.
     */
    private static function describeDroppedRow(array $submitted): string
    {
        $fields = [
            _('Name') => $submitted['name'] ?? '',
            _('Type') => $submitted['type'] ?? '',
            _('Content') => $submitted['content'] ?? '',
            _('Priority') => $submitted['prio'] ?? '',
            _('TTL') => $submitted['ttl'] ?? '',
            _('Comment') => $submitted['comment'] ?? '',
        ];

        $parts = [];
        foreach ($fields as $label => $value) {
            if ((string)$value !== '') {
                $parts[] = sprintf('%s: %s', $label, $value);
            }
        }

        if (isset($submitted['disabled']) && $submitted['disabled'] === 'on') {
            $parts[] = sprintf('%s: %s', _('Disabled'), _('Yes'));
        }

        return implode(', ', $parts);
    }

    /**
     * One line naming the stored value of every field the submission no longer agrees
     * with, so the operator can see what the zone holds before saving again. An empty
     * string means the submission and the zone agree on every editable field.
     */
    private static function describeStoredValues(array $stored, array $submitted): string
    {
        $fields = [
            'name' => [_('Name'), $stored['editable_name'] ?? ''],
            'type' => [_('Type'), $stored['type'] ?? ''],
            'content' => [_('Content'), $stored['content'] ?? ''],
            'ttl' => [_('TTL'), $stored['ttl'] ?? ''],
            'comment' => [_('Comment'), $stored['comment'] ?? ''],
        ];

        // Compared verbatim: a comment is stored as typed, so trimming here would treat a
        // whitespace-only edit as no edit and drop it.
        $parts = [];
        foreach ($fields as $field => [$label, $storedValue]) {
            $value = (string)$storedValue;
            if (isset($submitted[$field]) && $value !== (string)$submitted[$field]) {
                $parts[] = sprintf('%s: %s', $label, $value === '' ? '-' : $value);
            }
        }

        // Empty and zero are the same absent priority, so compare the two numerically
        // and let only the types that really carry one report a change.
        $storedPrio = (string)($stored['prio'] ?? '');
        if (isset($submitted['prio']) && (int)$storedPrio !== (int)$submitted['prio']) {
            $parts[] = sprintf('%s: %s', _('Priority'), $storedPrio === '' ? '-' : $storedPrio);
        }

        $submittedDisabled = isset($submitted['disabled']) && $submitted['disabled'] === 'on' ? 1 : 0;
        if ((int)($stored['disabled'] ?? 0) !== $submittedDisabled) {
            $parts[] = sprintf('%s: %s', _('Disabled'), $stored['disabled'] ? _('Yes') : _('No'));
        }

        if ($parts === []) {
            return '';
        }

        return sprintf(_('Not saved yet. The zone currently holds: %s'), implode(', ', $parts));
    }
}
