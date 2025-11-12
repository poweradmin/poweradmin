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
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

class DbUserGroupRepository implements UserGroupRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAll(): array
    {
        $query = "SELECT * FROM user_groups ORDER BY name ASC";
        $stmt = $this->db->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->mapRowToEntity($row), $results);
    }

    public function findByUserId(int $userId): array
    {
        $query = "SELECT ug.*
                  FROM user_groups ug
                  INNER JOIN user_group_members ugm ON ug.id = ugm.group_id
                  WHERE ugm.user_id = :user_id
                  ORDER BY ug.name ASC";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->mapRowToEntity($row), $results);
    }

    public function findById(int $id): ?UserGroup
    {
        $query = "SELECT * FROM user_groups WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapRowToEntity($row) : null;
    }

    public function findByName(string $name): ?UserGroup
    {
        $query = "SELECT * FROM user_groups WHERE name = :name";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapRowToEntity($row) : null;
    }

    public function save(UserGroup $group): UserGroup
    {
        if ($group->getId() === null) {
            return $this->insert($group);
        }

        return $this->update($group);
    }

    public function delete(int $id): bool
    {
        $query = "DELETE FROM user_groups WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function countMembers(int $groupId): int
    {
        $query = "SELECT COUNT(*) FROM user_group_members WHERE group_id = :group_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':group_id' => $groupId]);
        return (int)$stmt->fetchColumn();
    }

    public function countZones(int $groupId): int
    {
        $query = "SELECT COUNT(*) FROM zones_groups WHERE group_id = :group_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':group_id' => $groupId]);
        return (int)$stmt->fetchColumn();
    }

    private function insert(UserGroup $group): UserGroup
    {
        $query = "INSERT INTO user_groups (name, description, perm_templ, created_by, created_at, updated_at)
                  VALUES (:name, :description, :perm_templ, :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':name' => $group->getName(),
            ':description' => $group->getDescription(),
            ':perm_templ' => $group->getPermTemplId(),
            ':created_by' => $group->getCreatedBy()
        ]);

        $id = (int)$this->db->lastInsertId();

        return new UserGroup(
            $id,
            $group->getName(),
            $group->getDescription(),
            $group->getPermTemplId(),
            $group->getCreatedBy(),
            null, // createdAt will be fetched if needed
            null  // updatedAt will be fetched if needed
        );
    }

    private function update(UserGroup $group): UserGroup
    {
        $query = "UPDATE user_groups
                  SET name = :name,
                      description = :description,
                      perm_templ = :perm_templ
                  WHERE id = :id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':id' => $group->getId(),
            ':name' => $group->getName(),
            ':description' => $group->getDescription(),
            ':perm_templ' => $group->getPermTemplId()
        ]);

        return $this->findById($group->getId()) ?? $group;
    }

    private function mapRowToEntity(array $row): UserGroup
    {
        return new UserGroup(
            (int)$row['id'],
            $row['name'],
            $row['description'],
            (int)$row['perm_templ'],
            $row['created_by'] !== null ? (int)$row['created_by'] : null,
            $row['created_at'] ?? null,
            $row['updated_at'] ?? null
        );
    }
}
