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
 * Comments linked to individual records through record_comment_links.
 *
 * Only the SQL backend has the linking table; the API backend keeps one
 * comment per RRset and offers no implementation of this port.
 */
interface RecordLinkedCommentRepositoryInterface
{
    /**
     * Find the comment linked to a record.
     *
     * @param int|string $recordId The record ID
     */
    public function findByRecordId(int|string $recordId): ?RecordComment;

    /**
     * Delete the comment linked to a record, and the link.
     *
     * @param int|string $recordId The record ID
     */
    public function deleteByRecordId(int|string $recordId): bool;

    /**
     * Link a record to a comment.
     *
     * @param int|string $recordId The record ID
     * @param int $commentId The comment ID
     */
    public function linkRecordToComment(int|string $recordId, int $commentId): bool;

    /**
     * Unlink a record from its comment.
     *
     * @param int|string $recordId The record ID
     */
    public function unlinkRecord(int|string $recordId): bool;

    /**
     * Copy a legacy RRset comment to per-record links for every record of the
     * RRset that has none yet, except the given one.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param int|string $excludeRecordId Record ID to leave alone
     * @return bool True if siblings were actually migrated, false if skipped
     */
    public function migrateLegacyComments(int $domainId, string $name, string $type, int|string $excludeRecordId): bool;
}
