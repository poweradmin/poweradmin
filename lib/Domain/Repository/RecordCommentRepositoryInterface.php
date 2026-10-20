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

namespace Poweradmin\Domain\Repository;

use Poweradmin\Domain\Model\RecordComment;

/**
 * Persistence for RRset-level record comments; both backends implement it.
 * Per-record links are RecordLinkedCommentRepositoryInterface (SQL only).
 */
interface RecordCommentRepositoryInterface
{
    /**
     * Add a new comment to the database.
     *
     * @param RecordComment $comment The comment to add
     * @return RecordComment The added comment with ID
     */
    public function add(RecordComment $comment): RecordComment;

    /**
     * Delete all comments for an RRset (domain_id, name, type).
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @return bool
     */
    public function delete(int $domainId, string $name, string $type): bool;

    /**
     * Delete only legacy RRset comments (no per-record links).
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @return bool
     */
    public function deleteLegacyComment(int $domainId, string $name, string $type): bool;

    /**
     * Delete all comments for a domain.
     *
     * @param int $domainId Domain ID
     */
    public function deleteByDomainId(int $domainId): void;

    /**
     * Find a comment by RRset (domain_id, name, type).
     * Returns the first matching comment.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @return RecordComment|null
     */
    public function find(int $domainId, string $name, string $type): ?RecordComment;

    /**
     * Update an existing comment or create a new one.
     *
     * @param int $domainId Domain ID
     * @param string $oldName Previous record name
     * @param string $oldType Previous record type
     * @param RecordComment $comment The updated comment
     * @return RecordComment|null
     */
    public function update(int $domainId, string $oldName, string $oldType, RecordComment $comment): ?RecordComment;

    /**
     * Add a comment for a specific record.
     * Creates the comment and links it to the record.
     *
     * @param int|string $recordId The record ID
     * @param RecordComment $comment The comment to add
     * @return RecordComment|null The added comment with ID, or null on failure
     */
    public function addForRecord(int|string $recordId, RecordComment $comment): ?RecordComment;
}
