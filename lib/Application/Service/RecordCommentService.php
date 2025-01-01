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

        $recordComment = RecordComment::create($domainId, $name, $type, $comment, $account);
        return $this->recordCommentRepository->add($recordComment);
    }

    public function deleteComment(int $domainId, string $name, string $type): bool
    {
        return $this->recordCommentRepository->delete($domainId, $name, $type);
    }

    public function deleteCommentsByDomainId(string $domainId): void
    {
        $this->recordCommentRepository->deleteByDomainId($domainId);
    }

    public function updateComment(
        int $domainId,
        string $oldName,
        string $oldType,
        string $newName,
        string $newType,
        string $comment,
        string $account
    ): ?RecordComment {
        if ($comment === '') {
            $this->deleteComment($domainId, $oldName, $oldType);
            return null;
        }

        $recordComment = RecordComment::create($domainId, $newName, $newType, $comment, $account);
        return $this->recordCommentRepository->update($domainId, $oldName, $oldType, $recordComment);
    }

    public function findComment(int $domainId, string $name, string $type): ?RecordComment
    {
        return $this->recordCommentRepository->find($domainId, $name, $type);
    }
}
