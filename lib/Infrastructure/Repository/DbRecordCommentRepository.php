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
    private object $connection;

    public function __construct($connection)
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

    public function update(RecordComment $comment): RecordComment
    {
        if ($comment->getId() === null) {
            throw new \InvalidArgumentException("Record Comment must have an ID to update");
        }

        $stmt = $this->connection->prepare(
            "UPDATE comments 
             SET domain_id = :domain_id, 
                 name = :name, 
                 type = :type, 
                 modified_at = :modified_at, 
                 account = :account, 
                 comment = :comment 
             WHERE id = :id"
        );

        $stmt->execute([
            ':id' => $comment->getId(),
            ':domain_id' => $comment->getDomainId(),
            ':name' => $comment->getName(),
            ':type' => $comment->getType(),
            ':modified_at' => $comment->getModifiedAt(),
            ':account' => $comment->getAccount(),
            ':comment' => $comment->getComment()
        ]);

        return $comment;
    }

    public function delete(int $commentId): bool
    {
        $stmt = $this->connection->prepare(
            "DELETE FROM comments WHERE id = :id"
        );

        return $stmt->execute([':id' => $commentId]);
    }

    public function deleteCommentByDomainIdNameAndType(string $domainId, string $name, string $type): bool
    {
        $query = "DELETE FROM comments WHERE domain_id = :domain_id AND name = :name AND type = :type";
        $stmt = $this->connection->prepare($query);
        $stmt->bindParam(':domain_id', $domainId);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':type', $type);
        return $stmt->execute();
    }

    public function findById(int $commentId): ?RecordComment
    {
        $stmt = $this->connection->prepare(
            "SELECT * FROM comments WHERE id = :id"
        );
        $stmt->execute([':id' => $commentId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        return $data ? new RecordComment(
            (int)$data['id'],
            (int)$data['domain_id'],
            $data['name'],
            $data['type'],
            (int)$data['modified_at'],
            $data['account'],
            $data['comment']
        ) : null;
    }

    public function findByDomainId(int $domainId): array
    {
        $stmt = $this->connection->prepare(
            "SELECT * FROM comments WHERE domain_id = :domain_id"
        );
        $stmt->execute([':domain_id' => $domainId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($data) {
            return new RecordComment(
                (int)$data['id'],
                (int)$data['domain_id'],
                $data['name'],
                $data['type'],
                (int)$data['modified_at'],
                $data['account'],
                $data['comment']
            );
        }, $results);
    }
}