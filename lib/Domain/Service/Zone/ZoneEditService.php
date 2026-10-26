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
use Poweradmin\Domain\Port\TransactionInterface;
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
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Poweradmin\Domain\ValueObject\RecordIdentifier;

/**
 * Saves the zone editor's record table: the edit gate, the stale-form check,
 * the per-row diff and write, the record and zone comments, and the SOA
 * serial bump that closes a save.
 */
class ZoneEditService
{
    /** @var list<RecordLog>|null Audit lines held back until their transaction ends; null when not holding */
    private ?array $heldAudit = null;

    /**
     * @param bool $recordCommentsEnabled Whether record comments are edited and stored with a save
     * @param bool $zoneCommentsEnabled Whether the zone comment is stored with a save
     * @param TransactionInterface|null $transaction The local write transaction, when the backend has one;
     *                                               without it a save never moves records into each other's place
     */
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
        private readonly AuditLoggerInterface $audit,
        private readonly bool $recordCommentsEnabled,
        private readonly bool $zoneCommentsEnabled,
        private readonly ?TransactionInterface $transaction = null
    ) {
    }

    /**
     * Holds the audit lines of the rows written from now on until releaseAudit(), so the
     * lines of a save that is rolled back never reach the log.
     */
    public function holdAudit(): void
    {
        $this->heldAudit = [];
    }

    /**
     * Writes the held audit lines when the transaction committed, drops them otherwise,
     * and stops holding.
     */
    public function releaseAudit(bool $committed): void
    {
        $held = $this->heldAudit ?? [];
        $this->heldAudit = null;
        if ($committed) {
            foreach ($held as $log) {
                $log->write();
            }
        }
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
        $recordsWritten = false;

        $serialBefore = $this->currentSerial($submission->zoneId);
        $staleFormRejected = $this->isStale($submission);
        if ($staleFormRejected) {
            // Without the client filter every displayed row is posted, so a row that
            // differs from the zone need not be one the operator touched. Restoring
            // those would revert the other writer, so only a filtered post is kept.
            if ($submission->changedRowsOnly) {
                // The restored SOA row is shown beside the form's current serial, so it must carry it
                $rejectedRecords = array_map(fn(ZoneEditRow $row): ZoneEditRow => $this->keepCurrentSoaSerial($submission, $this->repointVanishedSoa($submission, $row)), $submission->rows);
                $rejectedZoneComment = $submission->zoneComment;
            }
        } else {
            [$changed, $errors, $recordsWritten, $rejectedRecords] = $this->saveRows($submission);
        }

        // A rejected form is rejected whole: writing the comment would persist half of a
        // submission the operator is being told to send again
        if (!$truncated && !$staleFormRejected && $this->zoneCommentsEnabled && $this->saveZoneComment($submission)) {
            $changed = true;
            $recordsWritten = true;
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
            serialBumped: $this->finalize($outcome, $submission->zoneId, $recordsWritten, $serialBefore),
            truncated: $truncated,
            errors: $errors,
            rejectedRecords: $rejectedRecords,
            rejectedZoneComment: $rejectedZoneComment
        );
    }

    /**
     * Writes the rows that differ from the zone.
     *
     * @return array{0: bool, 1: list<string>, 2: bool, 3: list<ZoneEditRow>} Whether any row differed,
     *   the refusals, whether any record was written (a record comment-only change writes none), and
     *   the rows refused because their record changed under them, handed back to the operator
     */
    private function saveRows(ZoneEditSubmission $submission): array
    {
        $changed = false;
        $errors = [];
        $recordsWritten = false;
        $unresolved = [];
        $plans = $this->planRows($submission);
        $changes = array_values(array_filter(array_column($plans, 2)));
        $vacated = $this->canSaveTogether() ? $this->vacatedRecords($changes) : [];
        // A row refused after another skipped it as vacated would leave a copy, so a swap is all or nothing
        $transaction = $vacated !== [] ? $this->transaction : null;
        $committed = false;
        if ($transaction !== null) {
            $transaction->begin();
            $this->holdAudit();
        }

        try {
            foreach ($submission->rows as $key => $posted) {
                // An API write can bump the serial and re-key the SOA or a sibling,
                // so those rows are planned when written
                [$row, $refused, $change] = $plans[$key] ?? $this->planRow($submission, $posted);
                if ($refused !== null) {
                    $changed = true;
                    $errors[] = (string)$refused->message;
                    $unresolved[] = $row;
                    continue;
                }
                if ($change === null) {
                    continue;
                }

                $written = $this->writeRow($submission, $change, $vacated);
                $changed = true;
                if (!$written->success) {
                    $errors[] = (string)$written->message;
                } elseif ($written->changed) {
                    $recordsWritten = true;
                }
            }

            if ($transaction !== null && $errors !== []) {
                $transaction->rollBack();
                $recordsWritten = false;
                $errors[] = _('No record was saved: some rows take each other\'s place, so they are saved together or not at all.');
            } else {
                $transaction?->commit();
                $committed = true;
            }
        } catch (\Throwable $e) {
            if ($transaction?->inTransaction()) {
                $transaction->rollBack();
            }
            throw $e;
        } finally {
            if ($transaction !== null) {
                $this->releaseAudit($committed);
            }
        }

        return [$changed, $errors, $recordsWritten, $unresolved];
    }

    private function canSaveTogether(): bool
    {
        return $this->transaction !== null && !$this->transaction->inTransaction();
    }

    /**
     * Each posted row with its refusal or its change against the zone, worked out once
     * before anything is written. API backend rows are left to the write loop.
     *
     * @return array<int, array{0: ZoneEditRow, 1: RecordWriteResult|null, 2: ZoneEditRowChange|null}> Keyed as
     *   the submission's rows
     */
    private function planRows(ZoneEditSubmission $submission): array
    {
        $plans = [];
        foreach ($submission->rows as $key => $row) {
            if (!RecordIdentifier::isEncoded($row->rid)) {
                $plans[$key] = $this->planRow($submission, $row);
            }
        }

        return $plans;
    }

    /**
     * @return array{0: ZoneEditRow, 1: RecordWriteResult|null, 2: ZoneEditRowChange|null} The row, the refusal
     *   when its record vanished, and the change (null when the row matches the zone)
     */
    private function planRow(ZoneEditSubmission $submission, ZoneEditRow $row): array
    {
        $row = $this->repointVanishedSoa($submission, $row);
        $refused = $this->refuseVanishedRecord($row);
        $change = $refused === null ? $this->diffRow($submission, $this->keepCurrentSoaSerial($submission, $row)) : null;

        return [$row, $refused, $change];
    }

    /**
     * Previews each change and collects the stored content of the records that pass
     * validation and really move away from an RRset another change in the same save writes
     * to. The writes run one by one, so a swap would otherwise be refused as a copy. The
     * caller writes them in one transaction and rolls back on any refusal; a save with
     * no such overlap gets an empty set and keeps saving row by row.
     *
     * @param list<ZoneEditRowChange> $changes
     * @return array<int|string, string> Stored content by record id
     */
    public function vacatedRecords(array $changes): array
    {
        $moving = [];
        $targets = [];
        foreach ($changes as $change) {
            $record = $change->record;
            $record['type'] = strtoupper(trim((string)$record['type']));
            $preview = $this->recordManager->previewEdit($record);
            if (!$preview->success || $preview->key === null) {
                continue;
            }
            $id = (string)$record['rid'];
            $targets[$id] = $preview->key['name'] . "\0" . $preview->key['type'];
            if ($preview->vacates !== null) {
                $moving[$id] = $preview->vacates;
            }
        }

        $vacated = [];
        foreach ($moving as $id => $stored) {
            $rrset = $stored['name'] . "\0" . $stored['type'];
            if (in_array($rrset, array_diff_key($targets, [$id => true]), true)) {
                $vacated[$id] = $stored['content'];
            }
        }

        return $vacated;
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

        $currentSerial = $this->currentSerial($submission->zoneId);

        return $submission->serial != $currentSerial
            && $this->config->get('misc', 'edit_conflict_resolution', 'last_writer_wins') === 'only_latest_version';
    }

    /**
     * On the API backend a record id encodes the record's content, so a record another
     * writer changed or deleted no longer resolves. Which record the operator meant cannot
     * be told from its name and type, so the row is refused rather than pointed elsewhere.
     */
    private function refuseVanishedRecord(ZoneEditRow $row): ?RecordWriteResult
    {
        if (!RecordIdentifier::isEncoded($row->rid) || $this->records->getRecordById($row->rid) !== null) {
            return null;
        }

        return RecordWriteResult::failure(
            _('Someone else changed this zone after you opened it. Review the records and save again.'),
            Refusal::CONFLICT
        );
    }

    /**
     * On the API backend the SOA row id encodes the serial, so any other write leaves a posted
     * SOA row unresolvable. A zone holds one SOA at its apex, so the row is pointed at the live
     * one; when it cannot be found the row is left for refuseVanishedRecord().
     */
    private function repointVanishedSoa(ZoneEditSubmission $submission, ZoneEditRow $row): ZoneEditRow
    {
        if (
            strtoupper(trim($row->type)) !== 'SOA'
            || !RecordIdentifier::isEncoded($row->rid)
            || $this->records->getRecordById($row->rid) !== null
        ) {
            return $row;
        }

        $live = $this->records->getRecordsByName($submission->zoneId, DnsHelper::zoneApexName($submission->zoneName), 'SOA');
        if (count($live) !== 1 || !isset($live[0]['id'])) {
            return $row;
        }

        return new ZoneEditRow($live[0]['id'], $row->name, $row->type, $row->content, $row->ttl, $row->prio, $row->disabled, $row->comment);
    }

    /**
     * A form opened before another save carries the SOA serial it was rendered with.
     * Writing that back would lower the serial and the bump after it could land on a
     * value secondaries have already seen, so an unedited serial becomes the current one.
     * A serial the operator typed (one that differs from the rendered one) is kept.
     */
    private function keepCurrentSoaSerial(ZoneEditSubmission $submission, ZoneEditRow $row): ZoneEditRow
    {
        if ($submission->serial === null || strtoupper(trim($row->type)) !== 'SOA') {
            return $row;
        }

        $posted = SOARecordManager::getSOASerial($row->content);
        $current = $this->currentSerial($submission->zoneId);
        if ($posted !== $submission->serial || $current === null || $current === $posted) {
            return $row;
        }

        return new ZoneEditRow($row->rid, $row->name, $row->type, SOARecordManager::setSOASerial($row->content, $current), $row->ttl, $row->prio, $row->disabled, $row->comment);
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

        $comment = '';
        if ($this->recordCommentsEnabled) {
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
     *
     * @param array<int|string, string> $vacated Stored content by id of the records the same save moves away
     */
    public function writeRow(ZoneEditSubmission $submission, ZoneEditRowChange $change, array $vacated = []): RecordWriteResult
    {
        $record = $change->record;
        // Uppercased only here, after the change check, so an untouched lowercase row is not rewritten
        $record['type'] = strtoupper(trim((string)$record['type']));
        $log = $change->log;

        $newComment = (string)($record['comment'] ?? '');
        $edited = $this->recordManager->editRecord($record, false, $this->recordCommentsEnabled ? [
            'content' => $newComment,
            'account' => $submission->username,
        ] : null, false, $vacated);
        if (!$edited->success) {
            return $edited;
        }

        $log->logAfter($record['rid'], $record);
        if ($this->heldAudit !== null) {
            $this->heldAudit[] = $log;
        } else {
            $log->write();
        }

        if ($this->recordCommentsEnabled) {
            $this->comments->updateCommentForRecord(
                $submission->zoneId,
                $record['name'],
                $record['type'],
                $newComment,
                RecordIdHelper::normalizeId($record['rid']),
                $submission->username
            );
            if ($this->config->get('misc', 'record_comments_sync')) {
                $this->commentSync->updateRelatedRecordComments($this->domains, $record, $newComment, $submission->username, $submission->userId);
            }
        }

        return $edited;
    }

    private function saveZoneComment(ZoneEditSubmission $submission): bool
    {
        // The page leaves the comment out when the operator did not touch it, so an
        // absent field keeps what the zone holds instead of writing an empty comment
        if ($submission->zoneComment === null) {
            return false;
        }
        $comment = $submission->zoneComment;
        if ($this->zones->getZoneComment($submission->zoneId) == $comment) {
            return false;
        }
        $this->zones->updateZoneComment($submission->zoneId, $comment);

        return true;
    }

    /**
     * Bumps the serial (and rectifies) for an accepted save. A save that wrote no
     * record (nothing changed, or only record comments) bumps by default so operators
     * can force a NOTIFY (#762), unless dns.bump_serial_on_unchanged_save is off.
     *
     * The API backend bumps the serial itself for every RRset it is sent (records,
     * record comments), so a serial that already moved is not bumped again; a save
     * that sent none (no-op, zone comment) is bumped here.
     *
     * @param bool $recordsWritten Whether a record or the zone comment was written
     * @param string|null $serialBefore The serial before this save wrote anything
     * @return bool Whether the serial moved
     */
    private function finalize(ZoneSaveOutcome $outcome, int $zoneId, bool $recordsWritten, ?string $serialBefore): bool
    {
        if (!$outcome->wasWritten()) {
            return false;
        }
        if (!$recordsWritten && !$this->config->get('dns', 'bump_serial_on_unchanged_save', true)) {
            return false;
        }

        $this->recordManager->finalizeZone($zoneId, true, $this->currentSerial($zoneId) !== $serialBefore);

        return $this->currentSerial($zoneId) !== $serialBefore;
    }

    private function currentSerial(int $zoneId): ?string
    {
        return SOARecordManager::getSOASerial($this->soaRecords->getSOARecord($zoneId));
    }
}
