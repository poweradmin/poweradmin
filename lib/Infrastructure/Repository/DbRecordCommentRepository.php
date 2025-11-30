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
use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;

class DbRecordCommentRepository implements RecordCommentRepositoryInterface
{
    private PDO $connection;
    private string $comments_table;
    private string $db_type;

    public function __construct(PDO $connection, ConfigurationManager $config)
    {
        $this->connection = $connection;
        $this->db_type = $config->get('database', 'type', 'mysql');
        $tableNameService = new TableNameService($config);
        $this->comments_table = $tableNameService->getTable(PdnsTable::COMMENTS);
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

    public function deleteByDomainId(int $domainId): void
    {
        $stmt = $this->connection->prepare("DELETE FROM {$this->comments_table} WHERE domain_id = :domainId");
        $stmt->bindParam(':domainId', $domainId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function find(int $domainId, string $name, string $type): ?RecordComment
    {
        // Only return legacy RRset comments (not per-record comments with rid: prefix)
        $prefix = RecordComment::RECORD_ID_PREFIX;
        $query = "SELECT * FROM {$this->comments_table} WHERE domain_id = :domain_id AND name = :name AND type = :type AND (account IS NULL OR account NOT LIKE :prefix) LIMIT 1";
        $stmt = $this->connection->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type,
            ':prefix' => $prefix . '%'
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
        ) : null;
    }

    public function update(int $domainId, string $oldName, string $oldType, RecordComment $comment): ?RecordComment
    {
        $account = $comment->getAccount();

        // If using record_id in account field (rid:XXX format), update only the specific comment for this record
        $recordId = RecordComment::getRecordIdFromAccount($account);
        if ($recordId !== null) {
            return $this->updateByRecordId($recordId, $comment);
        }

        // Legacy behavior: update all comments for the RRset (name + type)
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

    public function findByRecordId(int $recordId): ?RecordComment
    {
        // Use the prefix format to find per-record comments
        $accountValue = RecordComment::formatRecordIdForAccount($recordId);
        $query = "SELECT * FROM {$this->comments_table} WHERE account = :record_id LIMIT 1";
        $stmt = $this->connection->prepare($query);
        $stmt->execute([':record_id' => $accountValue]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? new RecordComment(
            $result['id'],
            $result['domain_id'],
            $result['name'],
            $result['type'],
            $result['modified_at'],
            $result['account'],
            $result['comment']
        ) : null;
    }

    public function deleteByRecordId(int $recordId): bool
    {
        // Use the prefix format to delete only per-record comments
        $accountValue = RecordComment::formatRecordIdForAccount($recordId);
        $query = "DELETE FROM {$this->comments_table} WHERE account = :record_id";
        $stmt = $this->connection->prepare($query);
        return $stmt->execute([':record_id' => $accountValue]);
    }

    /**
     * Update a specific comment identified by record ID stored in account field.
     */
    private function updateByRecordId(int $recordId, RecordComment $comment): ?RecordComment
    {
        // Use the prefix format to update per-record comments
        $accountValue = RecordComment::formatRecordIdForAccount($recordId);
        $stmt = $this->connection->prepare(
            "UPDATE {$this->comments_table}
             SET name = :new_name,
                 type = :new_type,
                 modified_at = :modified_at,
                 comment = :comment
             WHERE account = :record_id"
        );

        $success = $stmt->execute([
            ':record_id' => $accountValue,
            ':new_name' => $comment->getName(),
            ':new_type' => $comment->getType(),
            ':modified_at' => $comment->getModifiedAt(),
            ':comment' => $comment->getComment()
        ]);

        if (!$success) {
            return null;
        }

        if ($stmt->rowCount() === 0) {
            // No existing comment found, create new one
            return $this->add($comment);
        }

        return $this->findByRecordId($recordId);
    }

    /**
     * Delete legacy comments for an RRset (comments without the per-record marker).
     * This is used to clean up old-style shared comments when creating per-record comments.
     *
     * Legacy comments are those where account does NOT start with the per-record prefix (rid:).
     * This includes NULL, empty, usernames (including numeric usernames).
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @return bool
     */
    public function deleteLegacyComments(int $domainId, string $name, string $type): bool
    {
        // Delete comments that don't have the per-record prefix
        $prefix = RecordComment::RECORD_ID_PREFIX;

        $query = "DELETE FROM {$this->comments_table}
                  WHERE domain_id = :domain_id
                  AND name = :name
                  AND type = :type
                  AND (account IS NULL OR account NOT LIKE :prefix)";

        $stmt = $this->connection->prepare($query);
        return $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type,
            ':prefix' => $prefix . '%'
        ]);
    }

    /**
     * Find a legacy comment for an RRset (where account does not have the per-record prefix).
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @return RecordComment|null The legacy comment if found
     */
    public function findLegacyComment(int $domainId, string $name, string $type): ?RecordComment
    {
        $prefix = RecordComment::RECORD_ID_PREFIX;

        $query = "SELECT * FROM {$this->comments_table}
                  WHERE domain_id = :domain_id
                  AND name = :name
                  AND type = :type
                  AND (account IS NULL OR account NOT LIKE :prefix)
                  LIMIT 1";

        $stmt = $this->connection->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type,
            ':prefix' => $prefix . '%'
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
        ) : null;
    }

    public function migrateLegacyComment(int $domainId, string $name, string $type, array $recordIds, ?int $excludeRecordId = null): bool
    {
        // First, find if there's a legacy comment to migrate
        $legacyComment = $this->findLegacyComment($domainId, $name, $type);
        if ($legacyComment === null) {
            return false; // No legacy comment to migrate
        }

        $commentText = $legacyComment->getComment();
        $modifiedAt = time();

        // Create per-record comments for all records except the excluded one
        foreach ($recordIds as $recordId) {
            if ($excludeRecordId !== null && $recordId === $excludeRecordId) {
                continue; // Skip the record being edited (it will get its own new comment)
            }

            // Check if this record already has a per-record comment
            $existing = $this->findByRecordId($recordId);
            if ($existing !== null) {
                continue; // Already has a per-record comment, don't overwrite
            }

            // Create per-record comment for this record
            $newComment = RecordComment::createForRecord($domainId, $name, $type, $commentText, $recordId);
            $this->add($newComment);
        }

        // Now delete the legacy comment
        $this->deleteLegacyComments($domainId, $name, $type);

        return true;
    }
}
