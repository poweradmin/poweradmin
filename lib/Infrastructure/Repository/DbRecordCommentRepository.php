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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;

class DbRecordCommentRepository implements RecordCommentRepositoryInterface {
    private PDO $connection;
    private string $comments_table;

    public function __construct(PDO $connection, AppConfiguration $config)
    {
        $this->connection = $connection;
        $pdns_db_name = $config->get('pdns_db_name');
        $this->comments_table = $pdns_db_name ? $pdns_db_name . '.comments' : 'comments';
    }

    public function add(RecordComment $comment): RecordComment
    {
        $stmt = $this->connection->prepare(
            "INSERT INTO {$this->comments_table} (domain_id, name, type, modified_at, account, comment)
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
        $query = "DELETE FROM {$this->comments_table} WHERE domain_id = :domain_id AND name = :name AND type = :type";
        $stmt = $this->connection->prepare($query);
        return $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type
        ]);
    }

    public function deleteByDomainId(string $domainId): void
    {
        $stmt = $this->connection->prepare("DELETE FROM {$this->comments_table} WHERE domain_id = :domainId");
        $stmt->bindParam(':domainId', $domainId);
        $stmt->execute();
    }

    public function find(int $domainId, string $name, string $type): ?RecordComment
    {
        // Currently only one comment per record is supported
        $query = "SELECT * FROM {$this->comments_table} WHERE domain_id = :domain_id AND name = :name AND type = :type LIMIT 1";
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

    public function update(int $domainId, string $oldName, string $oldType, RecordComment $comment): ?RecordComment
    {
        $stmt = $this->connection->prepare(
            "UPDATE {$this->comments_table}
         SET name = :new_name,
             type = :new_type,
             modified_at = :modified_at,
             account = :account,
             comment = :comment
         WHERE domain_id = :domain_id
         AND name = :old_name
         AND type = :old_type"
        );

        $success = $stmt->execute([
            ':domain_id' => $domainId,
            ':old_name' => $oldName,
            ':old_type' => $oldType,
            ':new_name' => $comment->getName(),
            ':new_type' => $comment->getType(),
            ':modified_at' => $comment->getModifiedAt(),
            ':account' => $comment->getAccount(),
            ':comment' => $comment->getComment()
        ]);

        if (!$success) {
            return null;
        }

        if ($stmt->rowCount() === 0) {
            return $this->add($comment);
        }

        return $this->find($domainId, $comment->getName(), $comment->getType());
    }
}