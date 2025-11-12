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
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;

class DbZoneGroupRepository implements ZoneGroupRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByDomainId(int $domainId): array
    {
        $query = "SELECT * FROM zones_groups WHERE domain_id = :domain_id ORDER BY created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':domain_id' => $domainId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->mapRowToEntity($row), $results);
    }

    public function findByGroupId(int $groupId): array
    {
        $query = "SELECT * FROM zones_groups WHERE group_id = :group_id ORDER BY created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':group_id' => $groupId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->mapRowToEntity($row), $results);
    }

    public function add(int $domainId, int $groupId, ?int $zoneTemplId = null): ZoneGroup
    {
        // Check if ownership already exists
        if ($this->exists($domainId, $groupId)) {
            // Return existing ownership
            $query = "SELECT * FROM zones_groups WHERE domain_id = :domain_id AND group_id = :group_id";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':domain_id' => $domainId, ':group_id' => $groupId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $this->mapRowToEntity($row);
        }

        $query = "INSERT INTO zones_groups (domain_id, group_id, zone_templ_id, created_at)
                  VALUES (:domain_id, :group_id, :zone_templ_id, CURRENT_TIMESTAMP)";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':group_id' => $groupId,
            ':zone_templ_id' => $zoneTemplId
        ]);

        $id = (int)$this->db->lastInsertId();

        return new ZoneGroup($id, $domainId, $groupId, $zoneTemplId, null);
    }

    public function remove(int $domainId, int $groupId): bool
    {
        $query = "DELETE FROM zones_groups WHERE domain_id = :domain_id AND group_id = :group_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':group_id' => $groupId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function exists(int $domainId, int $groupId): bool
    {
        $query = "SELECT COUNT(*) FROM zones_groups WHERE domain_id = :domain_id AND group_id = :group_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':domain_id' => $domainId,
            ':group_id' => $groupId
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function mapRowToEntity(array $row): ZoneGroup
    {
        return new ZoneGroup(
            (int)$row['id'],
            (int)$row['domain_id'],
            (int)$row['group_id'],
            $row['zone_templ_id'] !== null ? (int)$row['zone_templ_id'] : null,
            $row['created_at'] ?? null
        );
    }
}
