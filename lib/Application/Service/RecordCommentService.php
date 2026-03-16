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

use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;

/**
 * Service for managing DNS record comments.
 *
 * Comments are stored per-record using a linking table (record_comment_links)
 * that associates individual record IDs with comment IDs in the PowerDNS comments table.
 * This allows different records with the same name and type to have different comments.
 */
class RecordCommentService
{
    private RecordCommentRepositoryInterface $recordCommentRepository;
    private bool $isApiBackend;

    public function __construct(
        RecordCommentRepositoryInterface $recordCommentRepository,
        ?DnsBackendProvider $backendProvider = null
    ) {
        $this->recordCommentRepository = $recordCommentRepository;
        $this->isApiBackend = $backendProvider !== null && $backendProvider->isApiBackend();
    }

    /**
     * Create a comment for a specific record.
     * Uses the linking table for per-record comment storage.
     *
     * @param int $domainId Domain/zone ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $comment Comment text
     * @param int|string $recordId Record ID for per-record comment linking
     * @param string|null $account Optional account/username
     * @return RecordComment|null
     */
    public function createCommentForRecord(
        int $domainId,
        string $name,
        string $type,
        string $comment,
        int|string $recordId,
        ?string $account = null
    ): ?RecordComment {
        // In API mode, per-record linking is not possible (encoded string IDs).
        // Delegate to RRset-level comment instead.
        if ($this->isApiBackend) {
            return $this->createComment($domainId, $name, $type, $comment, $account);
        }

        // Ensure legacy RRset comments are copied to other records before mutating this RRset
        $this->recordCommentRepository->migrateLegacyComments($domainId, $name, $type, $recordId);

        if ($comment === '') {
            // Delete per-record comment (linked via record_comment_links)
            $this->deleteCommentByRecordId($recordId);
            // Delete legacy RRset comments (now safe since other records have been migrated)
            $this->deleteLegacyComment($domainId, $name, $type);
            return null;
        }

        $recordComment = RecordComment::create($domainId, $name, $type, $comment, $account);
        $addedComment = $this->recordCommentRepository->addForRecord($recordId, $recordComment);

        if ($addedComment !== null) {
            // Clean up the legacy RRset comment row now that per-record links exist
            $this->deleteLegacyComment($domainId, $name, $type);
        }

        return $addedComment;
    }

    /**
     * Create a comment for an RRset (legacy method).
     * This does NOT use the linking table - use createCommentForRecord() for per-record comments.
     *
     * @param int $domainId Domain/zone ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $comment Comment text
     * @param string|null $account Optional account/username
     * @return RecordComment|null
     */
    public function createComment(
        int $domainId,
        string $name,
        string $type,
        string $comment,
        ?string $account = null
    ): ?RecordComment {
        if ($comment === '') {
            return null;
        }

        $recordComment = RecordComment::create($domainId, $name, $type, $comment, $account);
        return $this->recordCommentRepository->add($recordComment);
    }

    /**
     * Delete all comments for an RRset.
     * Also removes any links pointing to those comments.
     *
     * @param int $domainId Domain/zone ID
     * @param string $name Record name
     * @param string $type Record type
     * @return bool
     */
    public function deleteComment(int $domainId, string $name, string $type): bool
    {
        return $this->recordCommentRepository->delete($domainId, $name, $type);
    }

    /**
     * Delete legacy RRset comments that have no per-record links.
     *
     * @param int $domainId Domain/zone ID
     * @param string $name Record name
     * @param string $type Record type
     * @return bool
     */
    public function deleteLegacyComment(int $domainId, string $name, string $type): bool
    {
        return $this->recordCommentRepository->deleteLegacyComment($domainId, $name, $type);
    }

    /**
     * Delete comment for a specific record.
     * Removes the link and the associated comment.
     *
     * @param int|string $recordId Record ID
     * @return bool
     */
    public function deleteCommentByRecordId(int|string $recordId): bool
    {
        if ($this->isApiBackend) {
            return true;
        }
        return $this->recordCommentRepository->deleteByRecordId($recordId);
    }

    /**
     * Delete all comments for a domain.
     *
     * @param int $domainId Domain/zone ID
     */
    public function deleteCommentsByDomainId(int $domainId): void
    {
        $this->recordCommentRepository->deleteByDomainId($domainId);
    }

    /**
     * Update a comment for a specific record.
     * Uses the linking table for per-record comment storage.
     *
     * @param int $domainId Domain/zone ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $comment Comment text
     * @param int|string $recordId Record ID for per-record comment linking
     * @param string|null $account Optional account/username
     * @return RecordComment|null
     */
    public function updateCommentForRecord(
        int $domainId,
        string $name,
        string $type,
        string $comment,
        int|string $recordId,
        ?string $account = null
    ): ?RecordComment {
        // In API mode, per-record linking is not possible (encoded string IDs).
        // Delegate to RRset-level comment instead.
        if ($this->isApiBackend) {
            return $this->updateComment($domainId, $name, $type, $name, $type, $comment, $account);
        }

        if ($comment === '') {
            // Migrate legacy comments to other records before deleting
            $this->recordCommentRepository->migrateLegacyComments($domainId, $name, $type, $recordId);
            // Delete per-record comment (linked via record_comment_links)
            $this->deleteCommentByRecordId($recordId);
            // Delete legacy RRset comments (now safe since other records have been migrated)
            $this->deleteLegacyComment($domainId, $name, $type);
            return null;
        }

        $recordComment = RecordComment::create($domainId, $name, $type, $comment, $account);
        return $this->recordCommentRepository->addForRecord($recordId, $recordComment);
    }

    /**
     * Update a comment for an RRset when name/type changes (legacy method).
     *
     * @param int $domainId Domain/zone ID
     * @param string $oldName Previous record name
     * @param string $oldType Previous record type
     * @param string $newName New record name
     * @param string $newType New record type
     * @param string $comment Comment text
     * @param string|null $account Optional account/username
     * @return RecordComment|null
     */
    public function updateComment(
        int $domainId,
        string $oldName,
        string $oldType,
        string $newName,
        string $newType,
        string $comment,
        ?string $account = null
    ): ?RecordComment {
        // Delete old comment if name/type changed
        if ($oldName !== $newName || $oldType !== $newType) {
            $this->deleteComment($domainId, $oldName, $oldType);
        }

        if ($comment === '') {
            $this->deleteComment($domainId, $newName, $newType);
            return null;
        }

        $recordComment = RecordComment::create($domainId, $newName, $newType, $comment, $account);
        return $this->recordCommentRepository->update($domainId, $oldName, $oldType, $recordComment);
    }

    /**
     * Find a comment for an RRset.
     *
     * @param int $domainId Domain/zone ID
     * @param string $name Record name
     * @param string $type Record type
     * @return RecordComment|null
     */
    public function findComment(int $domainId, string $name, string $type): ?RecordComment
    {
        return $this->recordCommentRepository->find($domainId, $name, $type);
    }

    /**
     * Find a comment for a specific record.
     *
     * @param int $recordId Record ID
     * @return RecordComment|null
     */
    public function findCommentByRecordId(int|string $recordId): ?RecordComment
    {
        if ($this->isApiBackend) {
            return null;
        }
        return $this->recordCommentRepository->findByRecordId($recordId);
    }
}
