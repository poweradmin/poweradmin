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
use Poweradmin\Domain\Repository\RecordLinkedCommentRepositoryInterface;
use Poweradmin\Domain\Port\RecordCommentEditorInterface;

/**
 * Reads and writes the comment attached to a record.
 *
 * On the SQL backend comments are stored per record through a linking table
 * (record_comment_links), so records sharing a name and type can carry
 * different comments. The API backend has no such table: it keeps one comment
 * per RRset and the per-record operations report that they are unsupported.
 */
class RecordCommentService implements RecordCommentEditorInterface
{
    /**
     * @param RecordLinkedCommentRepositoryInterface|null $linkedComments Null on a backend without per-record links
     */
    public function __construct(
        private readonly RecordCommentRepositoryInterface $recordCommentRepository,
        private readonly ?RecordLinkedCommentRepositoryInterface $linkedComments = null
    ) {
    }

    /** Whether this backend can attach a comment to one record rather than its whole RRset */
    public function supportsPerRecordComments(): bool
    {
        return $this->linkedComments !== null;
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
        // Ensure legacy RRset comments are copied to other records before mutating this RRset
        $migrated = $this->migrateLegacyComments($domainId, $name, $type, $recordId);

        if ($comment === '') {
            // Delete per-record comment (linked via record_comment_links)
            $this->deleteCommentByRecordId($recordId);
            // Delete legacy RRset comments only when siblings were actually migrated
            if ($migrated) {
                $this->deleteLegacyComment($domainId, $name, $type);
            } elseif ($this->recordCommentRepository->find($domainId, $name, $type) !== null) {
                // API mode: can't migrate siblings, but a legacy RRset comment exists.
                // Store empty linked comment so fallback enrichment doesn't resurrect it.
                $sentinel = RecordComment::create($domainId, $name, $type, '', $account);
                $this->recordCommentRepository->addForRecord($recordId, $sentinel);
            }
            return null;
        }

        $recordComment = RecordComment::create($domainId, $name, $type, $comment, $account);
        $addedComment = $this->recordCommentRepository->addForRecord($recordId, $recordComment);

        if ($addedComment !== null && $migrated) {
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
     * @return bool False when the backend has no per-record comments
     */
    public function deleteCommentByRecordId(int|string $recordId): bool
    {
        return $this->linkedComments?->deleteByRecordId($recordId) ?? false;
    }

    /**
     * @return bool True if siblings were migrated; false when nothing was, or the backend cannot
     */
    private function migrateLegacyComments(int $domainId, string $name, string $type, int|string $recordId): bool
    {
        return $this->linkedComments?->migrateLegacyComments($domainId, $name, $type, $recordId) ?? false;
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
        if ($comment === '') {
            // Migrate legacy comments to other records before deleting
            $migrated = $this->migrateLegacyComments($domainId, $name, $type, $recordId);
            // Delete per-record comment (linked via record_comment_links)
            $this->deleteCommentByRecordId($recordId);
            // Delete legacy RRset comments only when siblings were actually migrated
            if ($migrated) {
                $this->deleteLegacyComment($domainId, $name, $type);
            } elseif ($this->recordCommentRepository->find($domainId, $name, $type) !== null) {
                // API mode: can't migrate siblings, but a legacy RRset comment exists.
                // Store empty linked comment so fallback enrichment doesn't resurrect it.
                $sentinel = RecordComment::create($domainId, $name, $type, '', $account);
                $this->recordCommentRepository->addForRecord($recordId, $sentinel);
            }
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
     * @param int|string $recordId Record ID
     * @return RecordComment|null Null when none is linked, or the backend has no per-record comments
     */
    public function findCommentByRecordId(int|string $recordId): ?RecordComment
    {
        return $this->linkedComments?->findByRecordId($recordId);
    }
}
