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

namespace Poweradmin\Domain\Repository;

use Poweradmin\Domain\Model\RecordComment;

interface RecordCommentRepositoryInterface
{
    public function add(RecordComment $comment): RecordComment;
    public function delete(int $domainId, string $name, string $type): bool;
    public function deleteByDomainId(int $domainId): void;

    public function find(int $domainId, string $name, string $type): ?RecordComment;
    public function update(int $domainId, string $oldName, string $oldType, RecordComment $comment): ?RecordComment;

    /**
     * Find a comment by record ID for per-record comment support.
     *
     * @param int $recordId The record ID
     * @return RecordComment|null
     */
    public function findByRecordId(int $recordId): ?RecordComment;

    /**
     * Delete a specific comment by record ID.
     *
     * @param int $recordId The record ID
     * @return bool
     */
    public function deleteByRecordId(int $recordId): bool;

    /**
     * Delete legacy comments for an RRset (where account is non-numeric username).
     * This is used to clean up old-style shared comments when creating per-record comments.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @return bool
     */
    public function deleteLegacyComments(int $domainId, string $name, string $type): bool;

    /**
     * Migrate legacy RRset comments to per-record comments for all records in the RRset.
     * This preserves existing comments when transitioning from legacy to per-record format.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param array $recordIds List of record IDs in the RRset to migrate comments to
     * @param int|null $excludeRecordId Optional record ID to exclude (the one being edited with new comment)
     * @return bool True if migration was performed, false if no legacy comment existed
     */
    public function migrateLegacyComment(int $domainId, string $name, string $type, array $recordIds, ?int $excludeRecordId = null): bool;
}
