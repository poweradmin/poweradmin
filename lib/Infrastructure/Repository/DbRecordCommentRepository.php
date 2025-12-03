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
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;

class DbRecordCommentRepository implements RecordCommentRepositoryInterface
{
    private PDO $connection;
    private string $comments_table;
    private string $records_table;
    private string $links_table = 'record_comment_links';

    public function __construct(PDO $connection, ConfigurationManager $config)
    {
        $this->connection = $connection;
        $tableNameService = new TableNameService($config);
        $this->comments_table = $tableNameService->getTable(PdnsTable::COMMENTS);
        $this->records_table = $tableNameService->getTable(PdnsTable::RECORDS);
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
        // First, delete any links to comments for this RRset
        $this->deleteLinksForRRset($domainId, $name, $type);

        // Then delete the comments themselves
        $query = "DELETE FROM {$this->comments_table} WHERE domain_id = :domain_id AND name = :name AND type = :type";
        $stmt = $this->connection->prepare($query);
        return $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type
        ]);
    }

    public function deleteLegacyComment(int $domainId, string $name, string $type): bool
    {
        // Delete only unlinked comments so per-record links survive
        // Use portable SQL (subquery) that works on MySQL, PostgreSQL, and SQLite
        $query = "DELETE FROM {$this->comments_table}
                  WHERE domain_id = :domain_id AND name = :name AND type = :type
                  AND id NOT IN (SELECT comment_id FROM {$this->links_table})";
        $stmt = $this->connection->prepare($query);
        return $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type
        ]);
    }

    public function deleteByDomainId(int $domainId): void
    {
        // First, delete all links for comments in this domain
        $query = "DELETE FROM {$this->links_table} WHERE comment_id IN (
            SELECT id FROM {$this->comments_table} WHERE domain_id = :domainId
        )";
        $stmt = $this->connection->prepare($query);
        $stmt->bindParam(':domainId', $domainId, PDO::PARAM_INT);
        $stmt->execute();

        // Then delete all comments
        $stmt = $this->connection->prepare("DELETE FROM {$this->comments_table} WHERE domain_id = :domainId");
        $stmt->bindParam(':domainId', $domainId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function find(int $domainId, string $name, string $type): ?RecordComment
    {
        // Only return unlinked comments (legacy RRset comments)
        // Exclude comments that are already linked to specific records
        $query = "SELECT c.* FROM {$this->comments_table} c
                  WHERE c.domain_id = :domain_id AND c.name = :name AND c.type = :type
                  AND NOT EXISTS (SELECT 1 FROM {$this->links_table} rcl WHERE rcl.comment_id = c.id)
                  LIMIT 1";
        $stmt = $this->connection->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $this->hydrateComment($result) : null;
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

    public function findByRecordId(int $recordId): ?RecordComment
    {
        $query = "SELECT c.* FROM {$this->comments_table} c
                  JOIN {$this->links_table} rcl ON rcl.comment_id = c.id
                  WHERE rcl.record_id = :record_id
                  LIMIT 1";
        $stmt = $this->connection->prepare($query);
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $this->hydrateComment($result) : null;
    }

    public function deleteByRecordId(int $recordId): bool
    {
        // First, get the comment_id linked to this record
        $query = "SELECT comment_id FROM {$this->links_table} WHERE record_id = :record_id";
        $stmt = $this->connection->prepare($query);
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return true; // No link exists, nothing to delete
        }

        $commentId = (int)$result['comment_id'];

        // Delete the link
        $this->unlinkRecord($recordId);

        // Delete the comment
        $query = "DELETE FROM {$this->comments_table} WHERE id = :comment_id";
        $stmt = $this->connection->prepare($query);
        $stmt->bindValue(':comment_id', $commentId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function linkRecordToComment(int $recordId, int $commentId): bool
    {
        // First, remove any existing link for this record
        $this->unlinkRecord($recordId);

        // Create the new link
        $query = "INSERT INTO {$this->links_table} (record_id, comment_id) VALUES (:record_id, :comment_id)";
        $stmt = $this->connection->prepare($query);
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $stmt->bindValue(':comment_id', $commentId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function unlinkRecord(int $recordId): bool
    {
        $query = "DELETE FROM {$this->links_table} WHERE record_id = :record_id";
        $stmt = $this->connection->prepare($query);
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function addForRecord(int $recordId, RecordComment $comment): ?RecordComment
    {
        // First, delete any existing comment for this record
        $this->deleteByRecordId($recordId);

        // Add the new comment
        $addedComment = $this->add($comment);

        if ($addedComment->getId() === null) {
            return null;
        }

        // Link the record to the comment
        $linked = $this->linkRecordToComment($recordId, $addedComment->getId());

        return $linked ? $addedComment : null;
    }

    /**
     * Delete all links to comments for a given RRset.
     */
    private function deleteLinksForRRset(int $domainId, string $name, string $type): void
    {
        $query = "DELETE FROM {$this->links_table} WHERE comment_id IN (
            SELECT id FROM {$this->comments_table}
            WHERE domain_id = :domain_id AND name = :name AND type = :type
        )";
        $stmt = $this->connection->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type
        ]);
    }

    /**
     * Migrate legacy RRset comments to per-record links for all records
     * in the RRset that don't have linked comments yet.
     * This prevents data loss when clearing a comment for one record.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param int $excludeRecordId Record ID to exclude (the one being edited)
     */
    public function migrateLegacyComments(int $domainId, string $name, string $type, int $excludeRecordId): void
    {
        // Find the legacy (unlinked) comment for this RRset
        $legacyComment = $this->find($domainId, $name, $type);
        if ($legacyComment === null) {
            return; // No legacy comment to migrate
        }

        // Find all records in the RRset that don't have linked comments (excluding the current record)
        $query = "SELECT r.id FROM {$this->records_table} r
                  WHERE r.domain_id = :domain_id AND r.name = :name AND r.type = :type
                  AND r.id != :exclude_record_id
                  AND NOT EXISTS (
                      SELECT 1 FROM {$this->links_table} rcl WHERE rcl.record_id = r.id
                  )";
        $stmt = $this->connection->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':name' => $name,
            ':type' => $type,
            ':exclude_record_id' => $excludeRecordId
        ]);
        $unlinkedRecords = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Create linked comments for each unlinked record (copying the legacy comment)
        foreach ($unlinkedRecords as $recordId) {
            $newComment = new RecordComment(
                null,
                $legacyComment->getDomainId(),
                $legacyComment->getName(),
                $legacyComment->getType(),
                time(),
                $legacyComment->getAccount(),
                $legacyComment->getComment()
            );
            $this->addForRecord((int)$recordId, $newComment);
        }
    }

    /**
     * Hydrate a RecordComment from a database row.
     */
    private function hydrateComment(array $row): RecordComment
    {
        return new RecordComment(
            (int)$row['id'],
            (int)$row['domain_id'],
            $row['name'],
            $row['type'],
            (int)$row['modified_at'],
            $row['account'],
            $row['comment']
        );
    }
}
