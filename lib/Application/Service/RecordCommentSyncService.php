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

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\RecordListingInterface;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\RecordCommentSyncInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Utility\DomainUtility;

/**
 * Keeps the comment on an A/AAAA record and its PTR counterpart in sync when either is created or edited.
 */
class RecordCommentSyncService implements RecordCommentSyncInterface
{
    private RecordCommentService $commentService;
    private ?RecordListingInterface $recordRepository;
    private bool $numericRecordIds;

    public function __construct(
        RecordCommentService $commentService,
        ?RecordListingInterface $recordRepository = null,
        ?BackendCapabilitiesInterface $backendProvider = null
    ) {
        $this->commentService = $commentService;
        $this->recordRepository = $recordRepository;
        $this->numericRecordIds = $backendProvider === null || $backendProvider->recordIdsAreNumeric();
    }

    public function syncCommentsForPtrRecord(
        int $domainId,
        int $ptrZoneId,
        string $domainFullName,
        string $ptrName,
        string $comment,
        string $account
    ): void {
        $this->commentService->createComment($domainId, $domainFullName, RecordType::A, $comment, $account);
        $this->commentService->createComment($ptrZoneId, $ptrName, RecordType::PTR, $comment, $account);
    }

    public function syncCommentsForDomainRecord(
        int $domainId,
        int $ptrZoneId,
        string $recordContent,
        string $ptrName,
        string $comment,
        string $account
    ): void {
        $this->commentService->createComment($ptrZoneId, $ptrName, RecordType::PTR, $comment, $account);
        $this->commentService->createComment($domainId, $recordContent, RecordType::A, $comment, $account);
    }

    public function updatePtrRecordComment(
        int $ptrZoneId,
        string $oldPtrName,
        string $newPtrName,
        string $comment,
        string $account
    ): void {
        $this->commentService->updateComment($ptrZoneId, $oldPtrName, RecordType::PTR, $newPtrName, RecordType::PTR, $comment, $account);
    }

    public function updateARecordComment(
        int $ptrZoneId,
        string $oldPtrName,
        string $newPtrName,
        string $comment,
        string $account
    ): void {
        $this->commentService->updateComment($ptrZoneId, $oldPtrName, RecordType::A, $newPtrName, RecordType::A, $comment, $account);
    }

    public function updateRelatedRecordComments(
        DomainRepositoryInterface $domainRepository,
        array $newRecordInfo,
        string $comment,
        string $userLogin
    ): void {
        if (in_array($newRecordInfo['type'], [RecordType::A, RecordType::AAAA])) {
            $ptrName = $newRecordInfo['type'] === RecordType::A
                ? DomainUtility::convertIPv4AddrToPtrRec($newRecordInfo['content'])
                : DomainUtility::convertIPv6AddrToPtrRec($newRecordInfo['content']);
            $ptrZoneId = $domainRepository->getBestMatchingZoneIdFromName($ptrName);
            if ($ptrZoneId !== -1) {
                $this->updateRecordComments($ptrZoneId, $ptrName, RecordType::PTR, $comment, $userLogin);
            }
        } elseif ($newRecordInfo['type'] === RecordType::PTR) {
            $hostname = rtrim($newRecordInfo['content'], '.');
            $contentDomainId = null;
            $parts = explode('.', $hostname);

            while (count($parts) > 1) {
                array_shift($parts);
                $zoneName = implode('.', $parts);
                $contentDomainId = $domainRepository->getDomainIdByName($zoneName);
                if ($contentDomainId !== null) {
                    break;
                }
            }

            if ($contentDomainId !== null) {
                $this->updateRecordComments($contentDomainId, $hostname, RecordType::A, $comment, $userLogin);
            }
        }
    }

    private function updateRecordComments(int $zoneId, string $name, string $type, string $comment, string $userLogin): void
    {
        // Encoded record ids cannot be linked per record; update the RRset comment instead
        if (!$this->numericRecordIds) {
            if ($type === RecordType::PTR) {
                $this->updatePtrRecordComment($zoneId, $name, $name, $comment, $userLogin);
            } else {
                $this->updateARecordComment($zoneId, $name, $name, $comment, $userLogin);
            }
            return;
        }

        if ($this->recordRepository !== null) {
            $rrsetRecords = $this->recordRepository->getRRSetRecords($zoneId, $name, $type);
            foreach ($rrsetRecords as $record) {
                $this->commentService->updateCommentForRecord(
                    $zoneId,
                    $name,
                    $type,
                    $comment,
                    (int)$record['id'],
                    $userLogin
                );
            }
        } else {
            if ($type === RecordType::PTR) {
                $this->updatePtrRecordComment($zoneId, $name, $name, $comment, $userLogin);
            } else {
                $this->updateARecordComment($zoneId, $name, $name, $comment, $userLogin);
            }
        }
    }
}
