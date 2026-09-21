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

namespace Poweradmin\Domain\Service\Zone;

use Poweradmin\Domain\Enum\ZoneSaveOutcome;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Port\RecordCommentEditorInterface;
use Poweradmin\Domain\Port\RecordCommentSyncInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordLookupInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\ZoneAccessPolicy;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordLog;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Utility\RecordIdHelper;

/**
 * Saves the zone editor's record table: the edit gate, the stale-form check,
 * the per-row diff and write, the record and zone comments, and the SOA
 * serial bump that closes a save.
 */
class ZoneEditService
{
    public function __construct(
        private readonly ConfigurationInterface $config,
        private readonly PermissionService $permissions,
        private readonly ZoneRepositoryInterface $zones,
        private readonly DomainRepositoryInterface $domains,
        private readonly RecordLookupInterface $records,
        private readonly RecordManagerInterface $recordManager,
        private readonly SOARecordManagerInterface $soaRecords,
        private readonly RecordCommentEditorInterface $comments,
        private readonly RecordCommentSyncInterface $commentSync,
        private readonly AuditLoggerInterface $audit
    ) {
    }

    public function save(ZoneEditSubmission $submission): ZoneSaveResult
    {
        // The page gate only proves view access; records are re-checked per row
        // but the zone comment write and the SOA serial bump are not
        $permEdit = $this->permissions->getEditPermissionLevelForZone($submission->userId, $submission->zoneId);
        if (!ZoneAccessPolicy::canEditZone($permEdit, $this->permissions->userOwnsZone($submission->userId, $submission->zoneId))) {
            return new ZoneSaveResult(ZoneSaveOutcome::FORBIDDEN);
        }

        // Secondary and consumer zones replicate from a primary: refuse any save
        // (records, comment, or serial bump) server-side, not just in the UI
        if (ZoneType::isReadOnly($this->domains->getDomainType($submission->zoneId))) {
            return new ZoneSaveResult(ZoneSaveOutcome::READ_ONLY);
        }

        // A truncated submission lost its trailing fields, so the zone comment must
        // not be processed either: it would be saved as empty
        $truncated = $submission->truncated;
        $rejectedRecords = [];
        $rejectedZoneComment = null;
        $errors = [];
        $changed = false;

        $staleFormRejected = $this->isStale($submission);
        if ($staleFormRejected) {
            // Without the client filter every displayed row is posted, so a row that
            // differs from the zone need not be one the operator touched. Restoring
            // those would revert the other writer, so only a filtered post is kept.
            if ($submission->changedRowsOnly) {
                $rejectedRecords = $submission->rows;
                $rejectedZoneComment = $submission->zoneComment;
            }
        } else {
            [$changed, $errors] = $this->saveRows($submission);
        }

        // A rejected form is rejected whole: writing the comment would persist half of a
        // submission the operator is being told to send again
        if (!$truncated && !$staleFormRejected && $this->config->get('interface', 'show_zone_comments', true)) {
            $changed = $this->saveZoneComment($submission) || $changed;
        }

        // A truncated save that changed nothing keeps the serial untouched
        if ($truncated && !$changed && $errors === [] && !$staleFormRejected) {
            return new ZoneSaveResult(ZoneSaveOutcome::NOTHING_SAVED, truncated: true);
        }

        $outcome = match (true) {
            $errors !== [] => ZoneSaveOutcome::WRITE_FAILED,
            $staleFormRejected => ZoneSaveOutcome::SERIAL_CONFLICT,
            $changed => ZoneSaveOutcome::UPDATED,
            default => ZoneSaveOutcome::NO_CHANGES,
        };

        return new ZoneSaveResult(
            $outcome,
            serialBumped: $this->finalize($outcome, $submission->zoneId),
            truncated: $truncated,
            errors: $errors,
            rejectedRecords: $rejectedRecords,
            rejectedZoneComment: $rejectedZoneComment
        );
    }

    /**
     * Writes the rows that differ from the zone.
     *
     * @return array{0: bool, 1: list<string>} Whether any row differed, and the refusals
     */
    private function saveRows(ZoneEditSubmission $submission): array
    {
        $changed = false;
        $errors = [];

        foreach ($submission->rows as $row) {
            $written = $this->saveRow($submission, $row);
            if ($written === null) {
                continue;
            }
            $changed = true;
            if (!$written->success) {
                $errors[] = (string)$written->message;
            }
        }

        return [$changed, $errors];
    }

    /**
     * Whether the form was rendered against an older serial and the strict
     * strategy applies. last_writer_wins is defined by saving over the other
     * writer, so a stale form under it still has to be processed.
     */
    private function isStale(ZoneEditSubmission $submission): bool
    {
        if ($submission->serial === null) {
            return false;
        }

        $currentSerial = SOARecordManager::getSOASerial($this->soaRecords->getSOARecord($submission->zoneId));

        return $submission->serial != $currentSerial
            && $this->config->get('misc', 'edit_conflict_resolution', 'last_writer_wins') === 'only_latest_version';
    }

    /**
     * Writes one edited row.
     *
     * @return RecordWriteResult|null The write, or null when the row matched the zone
     */
    private function saveRow(ZoneEditSubmission $submission, ZoneEditRow $row): ?RecordWriteResult
    {
        $change = $this->diffRow($submission, $row);
        if ($change === null) {
            return null;
        }

        return $this->writeRow($submission, $change);
    }

    /**
     * Normalises one row and compares it with the zone, writing nothing.
     * A change request files what this reports and replays it through writeRow().
     *
     * @return ZoneEditRowChange|null The change, or null when the row matched the zone
     */
    public function diffRow(ZoneEditSubmission $submission, ZoneEditRow $row): ?ZoneEditRowChange
    {
        $record = [
            'rid' => $row->rid,
            'zid' => $submission->zoneId,
            // Always the full name, so "@" and bare labels compare against what the zone holds
            'name' => DnsHelper::restoreZoneSuffix($row->name, $submission->zoneName),
            'type' => $row->type,
            'content' => $row->content,
            'ttl' => $row->ttl,
            'prio' => $row->prio,
            'disabled' => $row->disabled ? 1 : 0,
        ];
        // A comment the caller did not send must not compare against the stored one
        if ($row->comment !== null) {
            $record['comment'] = $row->comment;
        }

        $showComments = (bool)$this->config->get('interface', 'show_record_comments', false);
        $comment = '';
        if ($showComments) {
            $stored = $this->comments->findCommentByRecordId(RecordIdHelper::normalizeId($record['rid']))
                ?? $this->comments->findComment($submission->zoneId, $record['name'], $record['type']);
            $comment = $stored !== null ? $stored->getComment() : '';
        }

        $log = new RecordLog($this->audit, $this->records);
        $log->logPrior($record['rid'], $record['zid'], $comment);
        if (!$log->hasChanged($record)) {
            return null;
        }

        return new ZoneEditRowChange($record, $log);
    }

    /**
     * Writes one changed row without bumping the serial: the record, its audit
     * line, and the record comment with its PTR/A sync when comments are shown.
     */
    public function writeRow(ZoneEditSubmission $submission, ZoneEditRowChange $change): RecordWriteResult
    {
        $record = $change->record;
        $log = $change->log;
        $showComments = (bool)$this->config->get('interface', 'show_record_comments', false);

        $newComment = (string)($record['comment'] ?? '');
        $edited = $this->recordManager->editRecord($record, false, $showComments ? [
            'content' => $newComment,
            'account' => $submission->username,
        ] : null);
        if (!$edited->success) {
            return $edited;
        }

        $log->logAfter($record['rid'], $record);
        $log->write();

        if ($showComments) {
            $this->comments->updateCommentForRecord(
                $submission->zoneId,
                $record['name'],
                $record['type'],
                $newComment,
                RecordIdHelper::normalizeId($record['rid']),
                $submission->username
            );
            if ($this->config->get('misc', 'record_comments_sync')) {
                $this->commentSync->updateRelatedRecordComments($this->domains, $record, $newComment, $submission->username);
            }
        }

        return $edited;
    }

    private function saveZoneComment(ZoneEditSubmission $submission): bool
    {
        $comment = $submission->zoneComment ?? '';
        if ($this->zones->getZoneComment($submission->zoneId) == $comment) {
            return false;
        }
        $this->zones->updateZoneComment($submission->zoneId, $comment);

        return true;
    }

    /**
     * Bumps the serial (and rectifies) for an accepted save. A no-change save
     * bumps by default so operators can force a NOTIFY (#762).
     */
    private function finalize(ZoneSaveOutcome $outcome, int $zoneId): bool
    {
        if (!$outcome->wasWritten()) {
            return false;
        }
        if ($outcome === ZoneSaveOutcome::NO_CHANGES && !$this->config->get('dns', 'bump_serial_on_unchanged_save', true)) {
            return false;
        }

        $this->recordManager->finalizeZone($zoneId);

        return true;
    }
}
