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

namespace Poweradmin\Domain\Port;

use Poweradmin\Domain\Model\RecordComment;

/**
 * The per-record comment reads and writes the zone editor needs; the Application layer owns the storage.
 */
interface RecordCommentEditorInterface
{
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
    ): ?RecordComment;

    /**
     * Find a comment for an RRset.
     *
     * @param int $domainId Domain/zone ID
     * @param string $name Record name
     * @param string $type Record type
     * @return RecordComment|null
     */
    public function findComment(int $domainId, string $name, string $type): ?RecordComment;

    /**
     * Find a comment for a specific record.
     *
     * @param int|string $recordId Record ID
     * @return RecordComment|null
     */
    public function findCommentByRecordId(int|string $recordId): ?RecordComment;
}
