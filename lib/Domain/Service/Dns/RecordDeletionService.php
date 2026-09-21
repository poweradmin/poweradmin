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

namespace Poweradmin\Domain\Service\Dns;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\RecordLookupInterface;
use Poweradmin\Domain\Port\AuditLoggerInterface;

/**
 * Deletes a record and, when asked and when reverse handling is on, the PTR
 * of an A/AAAA record or the A/AAAA of a PTR record. A selection of records
 * is deleted the same way, with each affected zone finalized once.
 */
class RecordDeletionService
{
    /**
     * @param bool $reverseHandling Whether the counterpart record is considered at all
     */
    public function __construct(
        private readonly RecordLookupInterface $recordRepository,
        private readonly RecordManagerInterface $recordManager,
        private readonly ReverseRecordCreator $reverseRecordCreator,
        private readonly AuditLoggerInterface $audit,
        private readonly bool $reverseHandling
    ) {
    }

    /**
     * @param int|string $recordId Numeric id, or the encoded identifier of an API-backed record
     * @param bool $deletePtr Also remove the PTR of an A/AAAA record
     * @param bool $deleteForward Also remove the A/AAAA of a PTR record
     */
    public function deleteWithReverse(int $zoneId, int|string $recordId, bool $deletePtr, bool $deleteForward): RecordDeletionOutcome
    {
        $record = $this->recordRepository->getRecordFromId($recordId);
        if ($record === null) {
            return RecordDeletionOutcome::notFound(_('Record not found.'));
        }

        $type = $record['type'];
        $ptrCandidate = $this->reverseHandling && ($type === RecordType::A || $type === RecordType::AAAA);
        $forwardCandidate = $this->reverseHandling && $type === RecordType::PTR;

        $deleted = $this->recordManager->deleteRecord($recordId);
        if (!$deleted->success) {
            return RecordDeletionOutcome::failed((string)$deleted->message);
        }

        $this->audit->logRecordDelete(
            $zoneId,
            (string)$type,
            (string)$record['name'],
            (string)$record['content'],
            $record['ttl'],
            $record['prio'] ?? null
        );

        $ptrDeleted = $ptrCandidate && $deletePtr
            && $this->reverseRecordCreator->deleteReverseRecord($type, $record['content'], $record['name']);
        $forwardDeleted = $forwardCandidate && $deleteForward
            && $this->reverseRecordCreator->deleteForwardRecord($record['name'], $record['content']);

        return RecordDeletionOutcome::deleted($ptrCandidate, $ptrDeleted, $forwardCandidate, $forwardDeleted);
    }

    /**
     * Deletes a selection that may span zones, then bumps and rectifies each
     * affected zone once. A refused row is reported and the rest still go.
     *
     * @param list<int|string> $recordIds
     * @param bool $deletePtr Also remove the PTR of each deleted A/AAAA record
     */
    public function deleteMany(array $recordIds, bool $deletePtr): RecordBatchDeletionOutcome
    {
        $deleted = 0;
        $errors = [];
        $affectedZones = [];

        foreach ($recordIds as $recordId) {
            $record = $this->recordRepository->getRecordFromId($recordId);
            $zoneId = $record === null ? 0 : $this->recordRepository->getZoneIdFromRecordId($recordId);
            // 0 means the record no longer exists
            if ($record === null || $zoneId <= 0) {
                continue;
            }

            $result = $this->recordManager->deleteRecord($recordId, false);
            if (!$result->success) {
                $errors[] = (string)$result->message;
                continue;
            }

            $deleted++;
            $affectedZones[$zoneId] = true;
            $type = (string)$record['type'];
            $this->audit->logRecordDelete($zoneId, $type, (string)$record['name'], (string)$record['content'], $record['ttl'], $record['prio'] ?? null);

            if ($deletePtr && $this->reverseHandling && ($type === RecordType::A || $type === RecordType::AAAA)) {
                $this->reverseRecordCreator->deleteReverseRecord($type, $record['content'], $record['name']);
            }
        }

        foreach (array_keys($affectedZones) as $zoneId) {
            $this->recordManager->finalizeZone($zoneId);
        }

        return new RecordBatchDeletionOutcome($deleted, $errors);
    }
}
