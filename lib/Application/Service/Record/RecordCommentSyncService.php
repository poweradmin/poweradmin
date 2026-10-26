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

use Closure;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\RecordListingInterface;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\RecordCommentSyncInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Utility\DomainUtility;

/**
 * Keeps the comment on an A/AAAA record and its PTR counterpart in sync when either is created or edited.
 */
class RecordCommentSyncService implements RecordCommentSyncInterface
{
    private RecordCommentService $commentService;
    private PermissionService $permissions;
    private ?RecordListingInterface $recordRepository;
    private bool $numericRecordIds;
    private ?ChangeApprovalContext $approval;

    public function __construct(
        RecordCommentService $commentService,
        PermissionService $permissions,
        ?RecordListingInterface $recordRepository = null,
        ?BackendCapabilitiesInterface $backendProvider = null,
        ?ChangeApprovalContext $approval = null
    ) {
        $this->commentService = $commentService;
        $this->permissions = $permissions;
        $this->recordRepository = $recordRepository;
        $this->numericRecordIds = $backendProvider === null || $backendProvider->recordIdsAreNumeric();
        $this->approval = $approval;
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
        string $account,
        string $forwardType = RecordType::A
    ): void {
        $this->commentService->updateComment($ptrZoneId, $oldPtrName, $forwardType, $newPtrName, $forwardType, $comment, $account);
    }

    /**
     * @param (Closure(int): bool)|null $zoneAllowed Whether the comment may reach the related zone (an API key's zone scope)
     */
    public function updateRelatedRecordComments(
        DomainRepositoryInterface $domainRepository,
        array $newRecordInfo,
        string $comment,
        string $userLogin,
        int $userId,
        ?Closure $zoneAllowed = null
    ): void {
        if (in_array($newRecordInfo['type'], [RecordType::A, RecordType::AAAA])) {
            $ptrName = $newRecordInfo['type'] === RecordType::A
                ? DomainUtility::convertIPv4AddrToPtrRec($newRecordInfo['content'])
                : DomainUtility::convertIPv6AddrToPtrRec($newRecordInfo['content']);
            $ptrZoneId = $domainRepository->getBestMatchingZoneIdFromName($ptrName);
            if ($ptrZoneId !== -1 && $this->mayEditZone($domainRepository, $userId, $ptrZoneId, $zoneAllowed)) {
                $this->updateRecordComments($ptrZoneId, $ptrName, RecordType::PTR, $comment, $userLogin);
            }
        } elseif ($newRecordInfo['type'] === RecordType::PTR) {
            $hostname = rtrim($newRecordInfo['content'], '.');
            $contentDomainId = $domainRepository->getDomainIdByName($hostname);
            $parts = explode('.', $hostname);

            while ($contentDomainId === null && count($parts) > 1) {
                array_shift($parts);
                $zoneName = implode('.', $parts);
                $contentDomainId = $domainRepository->getDomainIdByName($zoneName);
                if ($contentDomainId !== null) {
                    break;
                }
            }

            if ($contentDomainId !== null && $this->mayEditZone($domainRepository, $userId, $contentDomainId, $zoneAllowed)) {
                $forwardType = str_ends_with(strtolower((string)($newRecordInfo['name'] ?? '')), '.ip6.arpa') ? RecordType::AAAA : RecordType::A;
                $this->updateRecordComments($contentDomainId, $hostname, $forwardType, $comment, $userLogin);
            }
        }
    }

    /**
     * The related zone comes from the record content, so it can belong to someone else,
     * or be one where the user must file a change request.
     */
    private function mayEditZone(DomainRepositoryInterface $domainRepository, int $userId, int $zoneId, ?Closure $zoneAllowed): bool
    {
        return $this->permissions->canEditZoneContent($userId, $zoneId, $domainRepository->getDomainType($zoneId))
            && $this->approval?->modeForZone($userId, $zoneId) !== ChangeApprovalPolicy::MODE_REQUEST
            && ($zoneAllowed === null || $zoneAllowed($zoneId));
    }

    private function updateRecordComments(int $zoneId, string $name, string $type, string $comment, string $userLogin): void
    {
        // Encoded record ids cannot be linked per record; update the RRset comment instead
        if (!$this->numericRecordIds) {
            if ($type === RecordType::PTR) {
                $this->updatePtrRecordComment($zoneId, $name, $name, $comment, $userLogin);
            } else {
                $this->updateARecordComment($zoneId, $name, $name, $comment, $userLogin, $type);
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
                $this->updateARecordComment($zoneId, $name, $name, $comment, $userLogin, $type);
            }
        }
    }
}
