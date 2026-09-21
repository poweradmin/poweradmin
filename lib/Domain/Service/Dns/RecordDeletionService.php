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

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\RecordLookupInterface;
use Poweradmin\Domain\Port\AuditLoggerInterface;

/**
 * Deletes a record and, when asked and when interface.add_reverse_record is on,
 * the PTR of an A/AAAA record or the A/AAAA of a PTR record.
 */
class RecordDeletionService
{
    public function __construct(
        private readonly RecordLookupInterface $recordRepository,
        private readonly RecordManagerInterface $recordManager,
        private readonly ReverseRecordCreator $reverseRecordCreator,
        private readonly AuditLoggerInterface $audit,
        private readonly ConfigurationInterface $config
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

        $reverseHandling = (bool)$this->config->get('interface', 'add_reverse_record', false);
        $type = $record['type'];
        $ptrCandidate = $reverseHandling && ($type === RecordType::A || $type === RecordType::AAAA);
        $forwardCandidate = $reverseHandling && $type === RecordType::PTR;

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
}
