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

use Closure;
use PDO;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\ChangeRequestNotifierInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;
use Poweradmin\Domain\Repository\RecordLinkedCommentRepositoryInterface;
use Poweradmin\Domain\Repository\RecordLookupInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Throwable;

/**
 * Files zone change requests and applies the reviewer's decision.
 *
 * Filing validates the rows the way a direct save would and stores the diff
 * without writing to the zone. Approval replays the stored actions through
 * RecordManager as the reviewing user, so RecordManager's own permission
 * checks still apply. Who may file or review is decided by the caller.
 */
class ZoneChangeRequestService
{
    /** Same ceiling as the change log's snapshot column */
    public const MAX_PAYLOAD_BYTES = 60000;

    private readonly DnsFormatter $formatter;

    /**
     * @param RecordCommentRepositoryInterface|null $recordComments Omit to leave comments of approved additions unstored
     * @param Closure|null $changeset fn(?int $zoneId, ?string $comment, callable $work): mixed grouping the
     *        applied writes in the change log; omitted, the work runs ungrouped
     * @param PermissionService|null $permissions Gates zone deletion on the reviewer's delete permission; omitted, no gate
     * @param ChangeRequestNotifierInterface|null $notifier Told about filed and decided requests; omitted, nobody is
     * @param Closure|null $zoneSnapshot fn(int $zoneId, string $zoneName): ?string rendering the zone as a zone
     *        file, kept with the request before an approved deletion; omitted, nothing is kept
     * @param RecordLinkedCommentRepositoryInterface|null $linkedComments Per-record comment links; null on a
     *        backend without them, where a delete request carries no stored comment
     */
    public function __construct(
        private readonly ZoneChangeRequestRepositoryInterface $requests,
        private readonly ZoneEditService $zoneEdit,
        private readonly DnsRecordValidationServiceInterface $validator,
        private readonly RecordLookupInterface $records,
        private readonly DomainRepositoryInterface $domains,
        private readonly ZoneRepositoryInterface $zones,
        private readonly RecordManagerInterface $recordManager,
        private readonly SOARecordManagerInterface $soaRecords,
        private readonly ZoneManagementService $zoneManagement,
        private readonly BackendCapabilitiesInterface $backend,
        private readonly PDO $db,
        private readonly ConfigurationInterface $config,
        private readonly ?RecordCommentRepositoryInterface $recordComments = null,
        private readonly ?Closure $changeset = null,
        private readonly ?PermissionService $permissions = null,
        private readonly ?ChangeRequestNotifierInterface $notifier = null,
        private readonly ?Closure $zoneSnapshot = null,
        private readonly ?RecordLinkedCommentRepositoryInterface $linkedComments = null
    ) {
        $this->formatter = new DnsFormatter($config);
    }

    /**
     * Files the rows of an editor submission that differ from the zone, plus the
     * zone comment when it changed. Nothing is written to the zone.
     */
    public function fileRecordEdits(ZoneEditSubmission $submission, ?string $comment = null): ZoneChangeRequestResult
    {
        $refused = $this->refuseReadOnlyZone($submission->zoneId);
        if ($refused !== null) {
            return $refused;
        }
        if ($submission->records !== null && !$submission->formComplete) {
            return $this->truncated();
        }

        $actions = [];
        $errors = [];
        foreach ($submission->records ?? [] as $record) {
            // Rows end with a hidden _complete marker; max_input_vars truncation drops it
            if (!is_array($record) || !isset($record['_complete'])) {
                return $this->truncated();
            }
            unset($record['_complete']);

            $change = $this->zoneEdit->diffRow($submission, $record);
            if ($change === null) {
                continue;
            }
            $before = $change->before();
            if (!isset($before['id']) || (int)($before['domain_id'] ?? 0) !== $submission->zoneId) {
                $errors[] = sprintf('Record %s was not found in this zone.', (string)($record['rid'] ?? ''));
                continue;
            }

            $row = $change->record;
            $row['content'] = $this->formatter->formatContent((string)$row['type'], (string)$row['content']);
            $error = $this->validate($row['rid'], $submission->zoneId, $row);
            if ($error !== null) {
                $errors[] = $error;
                continue;
            }

            $actions[] = [
                'op' => ZoneChangeRequest::OP_EDIT,
                'record_id' => (string)$row['rid'],
                'before' => $this->snapshot($before, $submission->zoneName),
                'after' => $this->afterState($row),
            ];
        }
        if ($errors !== []) {
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_VALIDATION, 'The request was not filed because some rows are invalid.', 400, $errors);
        }

        $zoneComment = $this->changedZoneComment($submission->zoneId, $submission->zoneComment);
        if ($actions === [] && $zoneComment === null) {
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_NO_CHANGES, 'Nothing differs from the zone, so no request was filed.');
        }

        return $this->store($submission->zoneId, $submission->zoneName, ZoneChangeRequest::KIND_RECORDS, $submission->userId, $submission->username, $comment, $submission->serial, $actions, $zoneComment);
    }

    /**
     * @param array<string, mixed> $record name, type, content, ttl, prio and optionally disabled (0/1) and comment
     */
    public function fileRecordAdd(int $zoneId, string $zoneName, array $record, int $userId, string $username, ?string $comment = null): ZoneChangeRequestResult
    {
        $refused = $this->refuseReadOnlyZone($zoneId);
        if ($refused !== null) {
            return $refused;
        }

        $row = [
            'name' => DnsHelper::restoreZoneSuffix((string)($record['name'] ?? ''), $zoneName),
            'type' => (string)($record['type'] ?? ''),
            'content' => $this->formatter->formatContent((string)($record['type'] ?? ''), (string)($record['content'] ?? '')),
            'ttl' => $record['ttl'] ?? $this->config->get('dns', 'ttl'),
            'prio' => $record['prio'] ?? 0,
            'disabled' => !empty($record['disabled']) ? 1 : 0,
            'comment' => (string)($record['comment'] ?? ''),
        ];
        $error = $this->validate(-1, $zoneId, $row);
        if ($error !== null) {
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_VALIDATION, $error, 400, [$error]);
        }
        if ($this->records->recordExists($zoneId, strtolower($row['name']), $row['type'], $row['content'])) {
            $error = 'A record with this hostname, type, and content already exists.';
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_VALIDATION, $error, 409, [$error]);
        }

        $actions = [['op' => ZoneChangeRequest::OP_ADD, 'after' => $this->afterState($row)]];

        return $this->store($zoneId, $zoneName, ZoneChangeRequest::KIND_RECORDS, $userId, $username, $comment, null, $actions, null);
    }

    public function fileRecordDelete(int $zoneId, int|string $recordId, int $userId, string $username, ?string $comment = null): ZoneChangeRequestResult
    {
        $refused = $this->refuseReadOnlyZone($zoneId);
        if ($refused !== null) {
            return $refused;
        }

        $recordId = RecordIdHelper::normalizeId($recordId);
        $stored = $this->records->getRecordFromId($recordId);
        if ($stored === null || (int)($stored['domain_id'] ?? 0) !== $zoneId) {
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_RECORD_NOT_FOUND, 'Record not found.', 404);
        }
        $zoneName = $this->domains->getDomainNameById($zoneId) ?? '';
        $stored['comment'] = $this->linkedComments?->findByRecordId($recordId)?->getComment();

        $actions = [[
            'op' => ZoneChangeRequest::OP_DELETE,
            'record_id' => (string)$recordId,
            'before' => $this->snapshot($stored, $zoneName),
        ]];

        return $this->store($zoneId, $zoneName, ZoneChangeRequest::KIND_RECORDS, $userId, $username, $comment, null, $actions, null);
    }

    public function fileZoneDelete(int $zoneId, int $userId, string $username, ?string $comment = null): ZoneChangeRequestResult
    {
        $zoneName = $this->domains->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_NOT_FOUND, 'Zone not found.', 404);
        }

        $actions = [['op' => ZoneChangeRequest::OP_ZONE_DELETE]];

        return $this->store($zoneId, $zoneName, ZoneChangeRequest::KIND_ZONE_DELETE, $userId, $username, $comment, null, $actions, null);
    }

    /**
     * Applies a pending request as the reviewing user. A refused or failed write
     * leaves the request in the failed state with the reason in its error field.
     */
    public function approve(int $requestId, int $reviewerId, string $reviewerName, ?string $comment = null): ZoneChangeRequestResult
    {
        $request = $this->pending($requestId, allowFailed: true);
        if ($request instanceof ZoneChangeRequestResult) {
            return $request;
        }

        // A failed request may be tried again once the reviewer has fixed what refused it
        $from = [ZoneChangeRequest::STATUS_PENDING, ZoneChangeRequest::STATUS_FAILED];
        if (!$this->requests->markReviewed($requestId, ZoneChangeRequest::STATUS_APPROVED, $reviewerId, $reviewerName, $comment, $from)) {
            return $this->decidedMeanwhile($requestId);
        }

        [$error, $status] = $this->apply($request, $reviewerId, $reviewerName, $comment);
        if ($error !== null) {
            $this->requests->markFailed($requestId, $error);
            $this->notify($requestId, fn(ZoneChangeRequest $r) => $this->notifier?->requestDecided($r));

            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_APPLY_FAILED, $error, $status, [], $requestId);
        }

        $this->requests->markApplied($requestId);
        $this->notify($requestId, fn(ZoneChangeRequest $r) => $this->notifier?->requestDecided($r));

        return ZoneChangeRequestResult::ok($requestId, 'Change request approved and applied.');
    }

    public function reject(int $requestId, int $reviewerId, string $reviewerName, ?string $comment = null): ZoneChangeRequestResult
    {
        $request = $this->pending($requestId);
        if ($request instanceof ZoneChangeRequestResult) {
            return $request;
        }

        if (!$this->requests->markReviewed($requestId, ZoneChangeRequest::STATUS_REJECTED, $reviewerId, $reviewerName, $comment)) {
            return $this->decidedMeanwhile($requestId);
        }
        $this->notify($requestId, fn(ZoneChangeRequest $r) => $this->notifier?->requestDecided($r));

        return ZoneChangeRequestResult::ok($requestId, 'Change request rejected.');
    }

    /**
     * Withdraws the caller's own pending request.
     */
    public function cancel(int $requestId, int $userId): ZoneChangeRequestResult
    {
        $request = $this->pending($requestId);
        if ($request instanceof ZoneChangeRequestResult) {
            return $request;
        }
        if ($request->requesterId !== $userId) {
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_NOT_REQUESTER, 'Only the requester can cancel this request.', 403);
        }

        if (!$this->requests->cancel($requestId)) {
            return $this->decidedMeanwhile($requestId);
        }
        $this->notify($requestId, fn(ZoneChangeRequest $r) => $this->notifier?->requestCancelled($r));

        return ZoneChangeRequestResult::ok($requestId, 'Change request cancelled.');
    }

    /**
     * Indexes of the actions the zone has moved away from: an edited or deleted
     * record whose stored row no longer matches the filed "before" state (or is
     * gone), and an addition the zone already holds.
     *
     * @return list<int>
     */
    public function staleActions(ZoneChangeRequest $request): array
    {
        $stale = [];
        foreach ($request->actions as $index => $action) {
            $op = $action['op'] ?? null;
            if ($op === ZoneChangeRequest::OP_ADD) {
                $after = is_array($action['after'] ?? null) ? $action['after'] : [];
                if ($this->records->recordExists($request->zoneId, strtolower((string)($after['name'] ?? '')), (string)($after['type'] ?? ''), (string)($after['content'] ?? ''))) {
                    $stale[] = $index;
                }
                continue;
            }
            if ($op !== ZoneChangeRequest::OP_EDIT && $op !== ZoneChangeRequest::OP_DELETE) {
                continue;
            }
            $before = is_array($action['before'] ?? null) ? $action['before'] : [];
            $current = $this->currentRow($request->zoneId, $action);
            if ($current === null || RecordManager::recordFieldsDiffer($before, $current)) {
                $stale[] = $index;
            }
        }

        return $stale;
    }

    /**
     * Whether the zone's SOA serial moved since the request's form was rendered.
     */
    public function baseSerialMismatch(ZoneChangeRequest $request): bool
    {
        if ($request->baseSerial === null) {
            return false;
        }
        $current = SOARecordManager::getSOASerial($this->soaRecords->getSOARecord($request->zoneId));

        return $current === null || (string)$current !== $request->baseSerial;
    }

    /**
     * @return array{0: string|null, 1: int} The failure text and HTTP status, or null when everything applied
     */
    private function apply(ZoneChangeRequest $request, int $reviewerId, string $reviewerName, ?string $reviewComment): array
    {
        $work = fn(): array => $request->isZoneDelete()
            ? $this->applyZoneDelete($request, $reviewerId)
            : $this->applyRecords($request, $reviewerId, $reviewerName);

        // The change log names the requester, since the reviewer is the session user who writes
        $reason = sprintf('Change request #%d from %s', $request->id, $request->requesterName);
        $comment = $request->requestComment ?? $reviewComment;
        if ($comment !== null && $comment !== '') {
            $reason .= ': ' . $comment;
        }

        try {
            return $this->changeset === null ? $work() : ($this->changeset)($request->zoneId, $reason, $work);
        } catch (Throwable $e) {
            return [$e->getMessage(), 500];
        }
    }

    /** @return array{0: string|null, 1: int} */
    private function applyZoneDelete(ZoneChangeRequest $request, int $reviewerId): array
    {
        // deleteZone() has no gate of its own, so the reviewer needs the same delete right as a direct delete
        if ($this->permissions !== null && !$this->permissions->canPerformZoneAction($reviewerId, $request->zoneId, Permission::PERM_ZONE_DELETE_OWN) && !$this->permissions->hasPermission($reviewerId, Permission::PERM_ZONE_DELETE_OTHERS)) {
            return ['You do not have the permission to delete a zone.', 403];
        }

        $this->keepSnapshot($request);
        $result = $this->zoneManagement->deleteZone($request->zoneId);
        if (!($result['success'] ?? false)) {
            return [(string)($result['message'] ?? 'Failed to delete zone'), (int)($result['status'] ?? 500)];
        }

        return [null, 200];
    }

    /**
     * A copy of the zone the reviewer is about to delete, so it can be re-imported by
     * hand. Skipped when it does not fit the column, which has the change log's ceiling.
     */
    private function keepSnapshot(ZoneChangeRequest $request): void
    {
        if ($this->zoneSnapshot === null) {
            return;
        }
        $snapshot = ($this->zoneSnapshot)($request->zoneId, $request->zoneName);
        if (is_string($snapshot) && $snapshot !== '' && strlen($snapshot) <= self::MAX_PAYLOAD_BYTES) {
            $this->requests->storeSnapshot($request->id, $snapshot);
        }
    }

    /**
     * Replays the actions in order and bumps the serial once. On a backend with
     * local transactions a failure leaves the zone untouched; otherwise the
     * error names the actions that had already landed.
     *
     * @return array{0: string|null, 1: int}
     */
    private function applyRecords(ZoneChangeRequest $request, int $reviewerId, string $reviewerName): array
    {
        $zoneId = $request->zoneId;
        $transactional = $this->backend->supportsLocalWriteTransaction() && !$this->db->inTransaction();
        if ($transactional) {
            $this->db->beginTransaction();
        }

        try {
            $failure = $this->replayActions($request, $reviewerId, $reviewerName, $transactional);
            if ($failure !== null) {
                if ($transactional) {
                    $this->db->rollBack();
                }

                return $failure;
            }

            if ($transactional) {
                // The serial moves with the rows; rectify reads committed rows, so it follows the commit
                $this->soaRecords->updateSOASerial($zoneId);
                $this->db->commit();
                $this->recordManager->finalizeZone($zoneId, false);
            } else {
                $this->recordManager->finalizeZone($zoneId);
            }
        } catch (Throwable $e) {
            if ($transactional) {
                $this->rollBackIfOpen();
            }
            throw $e;
        }

        return [null, 200];
    }

    /**
     * Runs every action and the zone comment write, stopping at the first refusal.
     *
     * @return array{0: string, 1: int}|null The failure text and status, or null when all landed
     */
    private function replayActions(ZoneChangeRequest $request, int $reviewerId, string $reviewerName, bool $rolledBackOnFailure): ?array
    {
        $applied = [];
        foreach ($request->actions as $index => $action) {
            $result = $this->applyAction($request, $action, $reviewerId, $reviewerName);
            if (!$result->success) {
                return [$this->describeFailure($index, $action, (string)$result->message, $applied, $rolledBackOnFailure), $result->status];
            }
            $applied[] = $index;
        }

        if ($request->zoneComment !== null) {
            $written = $this->recordManager->editZoneComment($request->zoneId, $request->zoneComment);
            if (!$written->success) {
                return [$this->describeFailure(count($request->actions), ['op' => 'zone_comment'], (string)$written->message, $applied, $rolledBackOnFailure), $written->status];
            }
        }

        return null;
    }

    /**
     * A commit that threw may already have closed the transaction.
     */
    private function rollBackIfOpen(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /** @param array<string, mixed> $action */
    private function applyAction(ZoneChangeRequest $request, array $action, int $reviewerId, string $reviewerName): RecordWriteResult
    {
        $zoneId = $request->zoneId;
        $after = is_array($action['after'] ?? null) ? $action['after'] : [];

        switch ($action['op'] ?? null) {
            case ZoneChangeRequest::OP_ADD:
                // Already there in full (a retry after a partial apply): the request asked for exactly this
                if ($this->rowMatching($zoneId, $after) !== null) {
                    return RecordWriteResult::ok();
                }

                return $this->applyAdd($zoneId, $after, $reviewerName);

            case ZoneChangeRequest::OP_EDIT:
                $recordId = $this->resolveRecordId($zoneId, $action);
                if ($recordId === null) {
                    // The previous attempt may have written it before failing later; API ids change with the content
                    return $this->rowMatching($zoneId, $after) !== null
                        ? RecordWriteResult::ok()
                        : RecordWriteResult::notFound('Record not found.');
                }
                $submission = new ZoneEditSubmission($zoneId, $request->zoneName, $reviewerId, $reviewerName, null, true, null, false, null);
                $change = $this->zoneEdit->diffRow($submission, $this->postedRow($recordId, $zoneId, $after));

                // The zone already holds this state, so there is nothing left to write
                return $change === null ? RecordWriteResult::ok() : $this->zoneEdit->writeRow($submission, $change);

            case ZoneChangeRequest::OP_DELETE:
                $recordId = $this->resolveRecordId($zoneId, $action);

                // Already gone: the request asked for exactly this
                return $recordId === null ? RecordWriteResult::ok() : $this->recordManager->deleteRecord($recordId, false);

            default:
                return RecordWriteResult::failure(sprintf('Unknown action "%s".', (string)($action['op'] ?? '')));
        }
    }

    /** @param array<string, mixed> $after */
    private function applyAdd(int $zoneId, array $after, string $reviewerName): RecordWriteResult
    {
        $comment = (string)($after['comment'] ?? '');
        $name = (string)($after['name'] ?? '');
        $type = (string)($after['type'] ?? '');
        $result = $this->recordManager->addRecordGetId(
            $zoneId,
            $name,
            $type,
            (string)($after['content'] ?? ''),
            (int)($after['ttl'] ?? 0),
            (int)($after['prio'] ?? 0),
            (int)($after['disabled'] ?? 0),
            false,
            $comment === '' ? null : ['content' => $comment, 'account' => $reviewerName]
        );
        if (
            $result->success
            && $result->recordId !== null
            && $comment !== ''
            && $this->recordComments !== null
            && $this->config->get('interface', 'show_record_comments', false)
        ) {
            $this->recordComments->addForRecord($result->recordId, RecordComment::create($zoneId, strtolower($name), $type, $comment, $reviewerName));
        }

        return $result;
    }

    /**
     * The stored row an edit or delete action refers to, by id first and by the
     * filed name, type and content when the id no longer resolves (API-backend
     * ids are encoded from those fields and change with them).
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>|null
     */
    private function currentRow(int $zoneId, array $action): ?array
    {
        $recordId = $action['record_id'] ?? null;
        if ((is_string($recordId) && $recordId !== '') || is_int($recordId)) {
            $stored = $this->records->getRecordFromId(RecordIdHelper::normalizeId($recordId));
            if ($stored !== null && (int)($stored['domain_id'] ?? 0) === $zoneId) {
                return $stored;
            }
        }

        $before = is_array($action['before'] ?? null) ? $action['before'] : [];
        $name = (string)($before['name'] ?? '');
        $type = (string)($before['type'] ?? '');
        if ($name === '' || $type === '') {
            return null;
        }
        foreach ($this->records->getRecordsByName($zoneId, $name, $type) as $row) {
            if ((string)($row['content'] ?? '') === (string)($before['content'] ?? '')) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A stored row equal to the wanted state in name, type, content, ttl, prio and disabled.
     *
     * @param array<string, mixed> $wanted
     * @return array<string, mixed>|null
     */
    private function rowMatching(int $zoneId, array $wanted): ?array
    {
        $name = (string)($wanted['name'] ?? '');
        $type = (string)($wanted['type'] ?? '');
        if ($name === '' || $type === '') {
            return null;
        }
        foreach ($this->records->getRecordsByName($zoneId, $name, $type) as $row) {
            if (!RecordManager::recordFieldsDiffer($wanted, $row)) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $action */
    private function resolveRecordId(int $zoneId, array $action): int|string|null
    {
        $row = $this->currentRow($zoneId, $action);

        return $row === null || !isset($row['id']) ? null : RecordIdHelper::normalizeId($row['id']);
    }

    /**
     * A filed "after" state in the shape the editor posts, so the same diff and
     * write path serves both a direct save and an approved request.
     *
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    private function postedRow(int|string $recordId, int $zoneId, array $after): array
    {
        $row = [
            'rid' => $recordId,
            'zid' => $zoneId,
            'name' => (string)($after['name'] ?? ''),
            'type' => (string)($after['type'] ?? ''),
            'content' => (string)($after['content'] ?? ''),
            'ttl' => (string)(int)($after['ttl'] ?? 0),
            'prio' => (string)(int)($after['prio'] ?? 0),
            'comment' => (string)($after['comment'] ?? ''),
        ];
        if (!empty($after['disabled'])) {
            $row['disabled'] = 'on';
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return string|null The first validation error, or null when the row is valid
     */
    private function validate(int|string $recordId, int $zoneId, array $row): ?string
    {
        $result = $this->validator->validateRecord(
            $recordId,
            $zoneId,
            (string)$row['type'],
            (string)$row['content'],
            (string)$row['name'],
            (int)($row['prio'] ?? 0),
            (int)($row['ttl'] ?? 0),
            (string)$this->config->get('dns', 'hostmaster'),
            (int)$this->config->get('dns', 'ttl')
        );

        return $result->isValid() ? null : $result->getFirstError();
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private function store(int $zoneId, string $zoneName, string $kind, int $userId, string $username, ?string $comment, ?string $baseSerial, array $actions, ?string $zoneComment): ZoneChangeRequestResult
    {
        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;
        // The same rule the change log applies to direct bulk edits, checked before the request exists
        if ($comment === null && $this->config->get('logging', 'require_change_comment', false)) {
            $error = 'A reason for this change is required.';
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_VALIDATION, $error, 400, [$error]);
        }
        if (strlen(ZoneChangeRequest::encodePayload($actions, $zoneComment)) > self::MAX_PAYLOAD_BYTES) {
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_PAYLOAD_TOO_LARGE, 'The request is too large to store; submit it as several smaller changes.', 413);
        }

        $id = $this->requests->create($zoneId, $zoneName, $kind, $userId, $username, $comment, $baseSerial, $actions, $zoneComment);
        $this->notify($id, fn(ZoneChangeRequest $r) => $this->notifier?->requestFiled($r));

        return ZoneChangeRequestResult::ok($id, 'Change request filed for review.');
    }

    private function changedZoneComment(int $zoneId, ?string $posted): ?string
    {
        if ($posted === null || !$this->config->get('interface', 'show_zone_comments', true)) {
            return null;
        }

        return $this->zones->getZoneComment($zoneId) == $posted ? null : $posted;
    }

    private function refuseReadOnlyZone(int $zoneId): ?ZoneChangeRequestResult
    {
        if (!ZoneType::isReadOnly($this->domains->getDomainType($zoneId))) {
            return null;
        }

        return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_READ_ONLY_ZONE, 'This zone replicates from a primary and cannot be edited.', 403);
    }

    /**
     * The request when it can still be decided, otherwise the refusal to relay.
     */
    private function pending(int $requestId, bool $allowFailed = false): ZoneChangeRequest|ZoneChangeRequestResult
    {
        $request = $this->requests->find($requestId);
        if ($request === null) {
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_NOT_FOUND, 'Change request not found.', 404);
        }
        if (!($allowFailed ? $request->canBeApplied() : $request->isPending())) {
            return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_NOT_PENDING, 'This change request has already been decided.', 409, [], $request->id);
        }

        return $request;
    }

    /**
     * A notification failure must not undo the event it announces.
     */
    private function notify(int $requestId, Closure $tell): void
    {
        if ($this->notifier === null) {
            return;
        }
        try {
            $request = $this->requests->find($requestId);
            if ($request !== null) {
                $tell($request);
            }
        } catch (Throwable) {
        }
    }

    private function decidedMeanwhile(int $requestId): ZoneChangeRequestResult
    {
        return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_NOT_PENDING, 'This change request has already been decided.', 409, [], $requestId);
    }

    private function truncated(): ZoneChangeRequestResult
    {
        return ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_TRUNCATED, 'The form was truncated by the server, so nothing was filed. Submit fewer changes at once.');
    }

    /**
     * @param array<string, mixed> $action
     * @param list<int> $applied
     */
    private function describeFailure(int $index, array $action, string $message, array $applied, bool $rolledBack): string
    {
        $text = sprintf('Action %d (%s) failed: %s.', $index + 1, (string)($action['op'] ?? '?'), rtrim(trim($message), '.'));
        if ($rolledBack) {
            return $text . ' Nothing was applied.';
        }
        if ($applied === []) {
            return $text . ' No action was applied.';
        }

        return $text . sprintf(' Applied before the failure: %s.', implode(', ', array_map(fn(int $i): string => (string)($i + 1), $applied)));
    }

    /**
     * The stored row in the change log's snapshot shape.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function snapshot(array $record, string $zoneName): array
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function afterState(array $row): array
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
}
