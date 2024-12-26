<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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

class RecordCommentSyncService
{
    const RECORD_TYPE_A = 'A';
    const RECORD_TYPE_PTR = 'PTR';
    private RecordCommentService $commentService;

    public function __construct(RecordCommentService $commentService)
    {
        $this->commentService = $commentService;
    }

    public function syncCommentsForPtrRecord(
        int    $domainId,
        int    $ptrZoneId,
        string $domainFullName,
        string $ptrName,
        string $comment,
        string $account
    ): void {
        $this->commentService->createComment($domainId, $domainFullName, self::RECORD_TYPE_A, $comment, $account);
        $this->commentService->createComment($ptrZoneId, $ptrName, self::RECORD_TYPE_PTR, $comment, $account);
    }

    public function syncCommentsForDomainRecord(
        int    $domainId,
        int    $ptrZoneId,
        string $recordContent,
        string $ptrName,
        string $comment,
        string $account
    ): void {
        $this->commentService->createComment($ptrZoneId, $ptrName, self::RECORD_TYPE_PTR, $comment, $account);
        $this->commentService->createComment($domainId, $recordContent, self::RECORD_TYPE_A, $comment, $account);
    }

    public function updatePtrRecordComment(
        int    $ptrZoneId,
        string $oldPtrName,
        string $newPtrName,
        string $comment,
        string $account
    ): void {
        $this->commentService->updateComment($ptrZoneId, $oldPtrName, self::RECORD_TYPE_PTR, $newPtrName, self::RECORD_TYPE_PTR, $comment, $account);
    }

    public function updateARecordComment(
        int    $ptrZoneId,
        string $oldPtrName,
        string $newPtrName,
        string $comment,
        string $account
    ): void {
        $this->commentService->updateComment($ptrZoneId, $oldPtrName, self::RECORD_TYPE_A, $newPtrName, self::RECORD_TYPE_A, $comment, $account);
    }
}
