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

namespace Poweradmin\Application\Service\Record;

use Exception;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordListingInterface;
use Poweradmin\Domain\Repository\RecordLookupInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Psr\Log\LoggerInterface;

/**
 * The single-record edit flow shared by the edit-record page and the API PUT:
 * name and content are stored as punycode with the zone suffix, the write goes
 * through the record manager, an SOA edit is bumped by the same rule, the PTR
 * follows when asked, the edit is audited and the record comment is stored or
 * carried along with a rename.
 */
class RecordEditService
{
    public function __construct(
        private readonly RecordManagerInterface $recordManager,
        private readonly RecordLookupInterface&RecordListingInterface $records,
        private readonly DomainRepositoryInterface $domains,
        private readonly SOARecordManagerInterface $soa,
        private readonly ReverseRecordCreator $reverseRecords,
        private readonly RecordCommentService $comments,
        private readonly RecordCommentSyncService $commentSync,
        private readonly AuditLoggerInterface $audit,
        private readonly ConfigurationInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function edit(RecordEditRequest $request): RecordEditResult
    {
        $name = DnsHelper::restoreZoneSuffix(DnsIdnService::toPunycode($request->name), $request->zoneName);
        $content = DnsIdnService::convertContentToPunycode($request->type, $request->content);

        $written = $this->recordManager->editRecord([
            'rid' => $request->recordId,
            'zid' => $request->zoneId,
            'name' => $name,
            'type' => $request->type,
            'content' => $content,
            'ttl' => $request->ttl,
            'prio' => $request->prio,
            'disabled' => $request->disabled,
        ], true, $request->comment === null ? null : [
            'content' => $request->comment,
            'account' => $request->username,
        ]);
        if (!$written->success) {
            return RecordEditResult::refused($written);
        }

        // The manager bumps the serial for every other type; an edited SOA carries
        // its own serial, which is bumped here unless the install opted out of
        // bumping and the manager skipped an unchanged save
        if (
            $request->bumpSoaSerial
            && $request->type === RecordType::SOA
            && ($this->config->get('dns', 'bump_serial_on_unchanged_save', true) || $this->savedRecordDiffers($request))
        ) {
            $this->soa->updateSOASerial($request->zoneId);
        }

        $stored = $this->records->getRecordFromId($request->recordId);
        if ($stored !== null) {
            $recordId = $stored['id'] ?? $request->recordId;
        } else {
            // The API backend re-keys a record when name, type, content or prio change;
            // the row is then rebuilt from the submitted values under its new id
            $recordId = $this->records->getNewRecordId($request->zoneId, $name, $request->type, $content) ?? $request->recordId;
            $stored = [
                'type' => $request->type,
                'name' => $name,
                'content' => $content,
                'ttl' => $request->ttl,
                'prio' => $request->prio,
                'disabled' => $request->disabled,
            ];
        }

        [$ptrUpdated, $ptrMessage] = $this->syncReverseRecord($request, $stored);

        $this->audit->logRecordEdit($request->zoneId, $request->current, $stored);
        $this->storeComment($request, $stored, $recordId);

        return new RecordEditResult($written, $recordId, $stored, $ptrUpdated, $ptrMessage);
    }

    /**
     * Whether the stored row differs from its pre-edit copy. In API mode the record
     * ID changes with name, type, content or prio, so a missing row is itself a change.
     */
    private function savedRecordDiffers(RecordEditRequest $request): bool
    {
        $saved = $this->records->getRecordFromId($request->recordId);

        return $saved === null || RecordManager::recordFieldsDiffer($request->current, $saved);
    }

    /**
     * Moves the PTR of an A/AAAA record along with the edit, so it neither keeps
     * pointing at the old address nor leaves the new one without one.
     *
     * @param array<string, mixed> $stored
     * @return array{0: bool|null, 1: string|null} Whether the sync succeeded (null when not attempted) and its message
     */
    private function syncReverseRecord(RecordEditRequest $request, array $stored): array
    {
        $oldType = strtoupper((string)($request->current['type'] ?? ''));
        $newType = strtoupper($request->type);
        $addressTypes = [RecordType::A, RecordType::AAAA];
        if (!$request->syncPtr || (!in_array($oldType, $addressTypes, true) && !in_array($newType, $addressTypes, true))) {
            return [null, null];
        }

        try {
            $result = $this->reverseRecords->updateReverseRecord(
                $oldType,
                (string)($request->current['content'] ?? ''),
                (string)($request->current['name'] ?? ''),
                $request->type,
                (string)$stored['content'],
                (string)$stored['name'],
                $request->zoneId,
                (int)$stored['ttl'],
                (int)($stored['prio'] ?? 0)
            );
        } catch (Exception $e) {
            $this->logger->error('PTR record update failed: {error}', ['error' => $e->getMessage()]);

            return [false, $e->getMessage()];
        }

        $message = isset($result['message']) ? (string)$result['message'] : null;

        return [!empty($result['success']), $message];
    }

    /**
     * Stores the supplied comment against the record (and its PTR/A counterpart when
     * the install syncs them); without one, a renamed record keeps its RRset comment.
     *
     * @param array<string, mixed> $stored
     */
    private function storeComment(RecordEditRequest $request, array $stored, int|string $recordId): void
    {
        if ($request->comment !== null) {
            $this->comments->updateCommentForRecord(
                $request->zoneId,
                (string)$stored['name'],
                (string)$stored['type'],
                $request->comment,
                RecordIdHelper::normalizeId($recordId),
                $request->username
            );
            if ($this->config->get('misc', 'record_comments_sync')) {
                $this->commentSync->updateRelatedRecordComments($this->domains, $stored, $request->comment, $request->username);
            }

            return;
        }

        $oldName = (string)($request->current['name'] ?? '');
        $oldType = (string)($request->current['type'] ?? '');
        if ($oldName === (string)$stored['name'] && $oldType === (string)$stored['type']) {
            return;
        }

        $existing = $this->comments->findComment($request->zoneId, $oldName, $oldType);
        if ($existing !== null) {
            $this->comments->updateComment(
                $request->zoneId,
                $oldName,
                $oldType,
                (string)$stored['name'],
                (string)$stored['type'],
                $existing->getComment(),
                $request->username
            );
        }
    }
}
