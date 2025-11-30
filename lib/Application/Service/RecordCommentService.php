<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;

class RecordCommentService
{
    private RecordCommentRepositoryInterface $recordCommentRepository;

    public function __construct(RecordCommentRepositoryInterface $recordCommentRepository)
    {
        $this->recordCommentRepository = $recordCommentRepository;
    }

    /**
     * Create a comment for a specific record.
     *
     * @param int $domainId Domain/zone ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $comment Comment text
     * @param int $recordId Record ID for per-record comment linking
     * @param array $rrsetRecordIds Optional list of all record IDs in the RRset for legacy comment migration
     * @return RecordComment|null
     */
    public function createCommentForRecord(
        int $domainId,
        string $name,
        string $type,
        string $comment,
        int $recordId,
        array $rrsetRecordIds = []
    ): ?RecordComment {
        if ($comment === '') {
            // Delete existing comment for this record if comment is empty
            $this->deleteCommentByRecordId($recordId);
            // Also delete legacy RRset comments if no other records in RRset have per-record comments
            if (empty($rrsetRecordIds) || count($rrsetRecordIds) <= 1) {
                $this->deleteLegacyComments($domainId, $name, $type);
            }
            return null;
        }

        // Delete existing comment for this specific record
        $this->deleteCommentByRecordId($recordId);

        // Migrate legacy shared comments to per-record comments for other records in the RRset
        // This preserves existing comments when transitioning from legacy to per-record format
        if (!empty($rrsetRecordIds)) {
            $this->migrateLegacyComment($domainId, $name, $type, $rrsetRecordIds, $recordId);
        } else {
            // No RRset info provided, just clean up legacy comments (backward compatible behavior)
            $this->deleteLegacyComments($domainId, $name, $type);
        }

        $recordComment = RecordComment::createForRecord($domainId, $name, $type, $comment, $recordId);
        return $this->recordCommentRepository->add($recordComment);
    }

    /**
     * Delete legacy comments (shared RRset-based comments with non-numeric account).
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @return bool
     */
    public function deleteLegacyComments(int $domainId, string $name, string $type): bool
    {
        return $this->recordCommentRepository->deleteLegacyComments($domainId, $name, $type);
    }

    /**
     * Migrate legacy RRset comments to per-record comments for all records in the RRset.
     * This preserves existing comments when transitioning from legacy to per-record format.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param array $recordIds List of record IDs in the RRset
     * @param int|null $excludeRecordId Optional record ID to exclude (the one being edited)
     * @return bool True if migration was performed
     */
    public function migrateLegacyComment(int $domainId, string $name, string $type, array $recordIds, ?int $excludeRecordId = null): bool
    {
        return $this->recordCommentRepository->migrateLegacyComment($domainId, $name, $type, $recordIds, $excludeRecordId);
    }

    /**
     * Legacy method: Create a comment for an RRset (name + type).
     * This deletes ALL comments for the RRset first.
     *
     * @deprecated Use createCommentForRecord() for per-record comments
     */
    public function createComment(
        int $domainId,
        string $name,
        string $type,
        string $comment,
        string $account
    ): ?RecordComment {
        if ($comment === '') {
            return null;
        }

        // Remove existing comments to avoid duplicates for the same record name and type
        $this->deleteComment($domainId, $name, $type);

        $recordComment = RecordComment::create($domainId, $name, $type, $comment, $account);
        return $this->recordCommentRepository->add($recordComment);
    }

    public function deleteComment(int $domainId, string $name, string $type): bool
    {
        return $this->recordCommentRepository->delete($domainId, $name, $type);
    }

    public function deleteCommentByRecordId(int $recordId): bool
    {
        return $this->recordCommentRepository->deleteByRecordId($recordId);
    }

    public function deleteCommentsByDomainId(int $domainId): void
    {
        $this->recordCommentRepository->deleteByDomainId($domainId);
    }

    /**
     * Update a comment for a specific record.
     *
     * @param int $domainId Domain/zone ID
     * @param string $newName New record name
     * @param string $newType New record type
     * @param string $comment Comment text
     * @param int $recordId Record ID for per-record comment linking
     * @param array $rrsetRecordIds Optional list of all record IDs in the RRset for legacy comment migration
     * @return RecordComment|null
     */
    public function updateCommentForRecord(
        int $domainId,
        string $newName,
        string $newType,
        string $comment,
        int $recordId,
        array $rrsetRecordIds = []
    ): ?RecordComment {
        if ($comment === '') {
            $this->deleteCommentByRecordId($recordId);
            // Also delete legacy RRset comments if no other records in RRset have per-record comments
            if (empty($rrsetRecordIds) || count($rrsetRecordIds) <= 1) {
                $this->deleteLegacyComments($domainId, $newName, $newType);
            }
            return null;
        }

        // Migrate legacy shared comments to per-record comments for other records in the RRset
        // This preserves existing comments when transitioning from legacy to per-record format
        if (!empty($rrsetRecordIds)) {
            $this->migrateLegacyComment($domainId, $newName, $newType, $rrsetRecordIds, $recordId);
        } else {
            // No RRset info provided, just clean up legacy comments (backward compatible behavior)
            $this->deleteLegacyComments($domainId, $newName, $newType);
        }

        $recordComment = RecordComment::createForRecord($domainId, $newName, $newType, $comment, $recordId);
        return $this->recordCommentRepository->update($domainId, $newName, $newType, $recordComment);
    }

    /**
     * Legacy method: Update a comment for an RRset (name + type).
     *
     * @deprecated Use updateCommentForRecord() for per-record comments
     */
    public function updateComment(
        int $domainId,
        string $oldName,
        string $oldType,
        string $newName,
        string $newType,
        string $comment,
        string $account
    ): ?RecordComment {
        $this->deleteComment($domainId, $oldName, $oldType);

        $recordComment = RecordComment::create($domainId, $newName, $newType, $comment, $account);
        return $this->recordCommentRepository->update($domainId, $oldName, $oldType, $recordComment);
    }

    public function findComment(int $domainId, string $name, string $type): ?RecordComment
    {
        return $this->recordCommentRepository->find($domainId, $name, $type);
    }

    public function findCommentByRecordId(int $recordId): ?RecordComment
    {
        return $this->recordCommentRepository->findByRecordId($recordId);
    }
}
