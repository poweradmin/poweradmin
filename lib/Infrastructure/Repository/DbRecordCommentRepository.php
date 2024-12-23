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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;

class DbRecordCommentRepository implements RecordCommentRepositoryInterface {
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function add(RecordComment $comment): RecordComment
    {
        $stmt = $this->connection->prepare(
            "INSERT INTO comments (domain_id, name, type, modified_at, account, comment) 
             VALUES (:domain_id, :name, :type, :modified_at, :account, :comment)"
        );

        $stmt->execute([
            ':domain_id' => $comment->getDomainId(),
            ':name' => $comment->getName(),
            ':type' => $comment->getType(),
            ':modified_at' => $comment->getModifiedAt(),
            ':account' => $comment->getAccount(),
            ':comment' => $comment->getComment()
        ]);

        $id = (int)$this->connection->lastInsertId();

        return new RecordComment(
            $id,
            $comment->getDomainId(),
            $comment->getName(),
            $comment->getType(),
            $comment->getModifiedAt(),
            $comment->getAccount(),
            $comment->getComment()
        );
    }

    public function delete(int $domainId, string $name, string $type): bool
    {
        $query = "DELETE FROM comments WHERE domain_id = :domain_id AND name = :name AND type = :type";
        $stmt = $this->connection->prepare($query);
        return $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type
        ]);
    }

    public function find(int $domainId, string $name, string $type): ?RecordComment
    {
        $query = "SELECT * FROM comments WHERE domain_id = :domain_id AND name = :name AND type = :type";
        $stmt = $this->connection->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? new RecordComment(
            $result['id'],
            $result['domain_id'],
            $result['name'],
            $result['type'],
            $result['modified_at'],
            $result['account'],
            $result['comment']
        ): null;
    }
}