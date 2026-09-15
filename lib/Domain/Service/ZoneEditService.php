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

namespace Poweradmin\Domain\Service;

use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Domain\Enum\ZoneSaveOutcome;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneWriteRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
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
        private readonly ZoneWriteRepositoryInterface $zones,
        private readonly DomainRepositoryInterface $domains,
        private readonly RecordRepositoryInterface $records,
        private readonly RecordManagerInterface $recordManager,
        private readonly SOARecordManagerInterface $soaRecords,
        private readonly RecordCommentService $comments,
        private readonly RecordCommentSyncService $commentSync,
        private readonly AuditService $audit
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

        $records = $submission->records;
        // A truncated POST (max_input_vars) drops the form's trailing fields, so the
        // zone comment must not be processed either: it would be saved as empty
        $truncated = $records !== null && !$submission->formComplete;
        $staleFormRejected = false;
        $rejectedRecords = [];
        $rejectedZoneComment = null;
        $errors = [];
        $changed = false;

        // The client omits unchanged rows but always sends form_complete, so either
        // counts as an edit-form save and both run the stale-form serial check
        if ($records !== null || $submission->formComplete) {
            $staleFormRejected = $this->isStale($submission);

            if ($staleFormRejected) {
                // Without the client filter every displayed row is posted, so a row that
                // differs from the zone need not be one the operator touched. Restoring
                // those would revert the other writer, so only a filtered post is kept.
                if ($submission->changedRowsOnly) {
                    $rejectedRecords = $records ?? [];
                    $rejectedZoneComment = $submission->zoneComment;
                }
            } else {
                [$changed, $errors, $rowsTruncated] = $this->saveRows($submission, $records ?? []);
                $truncated = $truncated || $rowsTruncated;
            }
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
     * @param array<int|string, mixed> $records
     * @return array{0: bool, 1: list<string>, 2: bool} Whether any row differed, the refusals, whether rows were truncated
     */
    private function saveRows(ZoneEditSubmission $submission, array $records): array
    {
        $changed = false;
        $errors = [];
        $truncated = false;

        foreach ($records as $record) {
            // Rows end with a hidden _complete marker; max_input_vars truncation
            // drops it, so skip such rows and flag the partial save
            if (!is_array($record) || !isset($record['_complete'])) {
                $truncated = true;
                continue;
            }
            unset($record['_complete']);

            $written = $this->saveRow($submission, $record);
            if ($written === null) {
                continue;
            }
            $changed = true;
            if (!$written->success) {
                $errors[] = (string)$written->message;
            }
        }

        return [$changed, $errors, $truncated];
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
     * @param array<string, mixed> $record
     * @return RecordWriteResult|null The write, or null when the row matched the zone
     */
    private function saveRow(ZoneEditSubmission $submission, array $record): ?RecordWriteResult
    {
        // Always the full name, so "@" and bare labels compare against what the zone holds
        if (isset($record['name'])) {
            $record['name'] = DnsHelper::restoreZoneSuffix((string)$record['name'], $submission->zoneName);
        }
        $record['disabled'] = isset($record['disabled']) && $record['disabled'] == 'on' ? 1 : 0;

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
