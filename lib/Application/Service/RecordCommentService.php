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

use InvalidArgumentException;
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
        ?string $account = null
    ): RecordComment {
        $recordComment = RecordComment::create($domainId, $name, $type, $comment, $account);
        return $this->recordCommentRepository->add($recordComment);
    }

    public function updateComment(
        int $commentId,
        ?string $name = null,
        ?string $type = null,
        ?string $comment = null,
        ?string $account = null
    ): RecordComment {
        $existingComment = $this->recordCommentRepository->findById($commentId);

        if (!$existingComment) {
            throw new InvalidArgumentException("Record Comment not found");
        }

        $updatedComment = $existingComment->update($name, $type, $comment, $account);
        return $this->recordCommentRepository->update($updatedComment);
    }

    public function deleteComment(int $commentId): bool
    {
        return $this->recordCommentRepository->delete($commentId);
    }

    public function getCommentsByDomainId(int $domainId): array
    {
        return $this->recordCommentRepository->findByDomainId($domainId);
    }
}
