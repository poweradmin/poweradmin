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
use Poweradmin\Domain\Model\UserGroupMember;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;

class DbUserGroupMemberRepository implements UserGroupMemberRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByGroupId(int $groupId): array
    {
        $query = "SELECT ugm.*, u.username, u.fullname, u.email
                  FROM user_group_members ugm
                  LEFT JOIN users u ON ugm.user_id = u.id
                  WHERE ugm.group_id = :group_id
                  ORDER BY ugm.created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':group_id' => $groupId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->mapRowToEntity($row), $results);
    }

    public function findByUserId(int $userId): array
    {
        $query = "SELECT * FROM user_group_members WHERE user_id = :user_id ORDER BY created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->mapRowToEntity($row), $results);
    }

    public function add(int $groupId, int $userId): UserGroupMember
    {
        // Check if membership already exists
        if ($this->exists($groupId, $userId)) {
            // Return existing membership
            $query = "SELECT * FROM user_group_members WHERE group_id = :group_id AND user_id = :user_id";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $this->mapRowToEntity($row);
        }

        $query = "INSERT INTO user_group_members (group_id, user_id, created_at)
                  VALUES (:group_id, :user_id, CURRENT_TIMESTAMP)";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':group_id' => $groupId,
            ':user_id' => $userId
        ]);

        $id = (int)$this->db->lastInsertId();

        return new UserGroupMember($id, $groupId, $userId, null);
    }

    public function remove(int $groupId, int $userId): bool
    {
        $query = "DELETE FROM user_group_members WHERE group_id = :group_id AND user_id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':group_id' => $groupId,
            ':user_id' => $userId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function exists(int $groupId, int $userId): bool
    {
        $query = "SELECT COUNT(*) FROM user_group_members WHERE group_id = :group_id AND user_id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':group_id' => $groupId,
            ':user_id' => $userId
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function mapRowToEntity(array $row): UserGroupMember
    {
        return new UserGroupMember(
            (int)$row['id'],
            (int)$row['group_id'],
            (int)$row['user_id'],
            $row['created_at'] ?? null,
            $row['username'] ?? null,
            $row['fullname'] ?? null,
            $row['email'] ?? null
        );
    }
}
