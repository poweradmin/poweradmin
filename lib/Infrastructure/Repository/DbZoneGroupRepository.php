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
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;

class DbZoneGroupRepository implements ZoneGroupRepositoryInterface
{
    private PDO $db;
    private TableNameService $tableNameService;

    public function __construct(PDO $db, ?ConfigurationInterface $config = null)
    {
        $this->db = $db;
        if ($config) {
            $this->tableNameService = new TableNameService($config);
        }
    }

    public function findByDomainId(int $domainId): array
    {
        $query = "SELECT * FROM zones_groups WHERE zone_id = :zone_id ORDER BY assigned_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':zone_id' => $domainId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->mapRowToEntity($row), $results);
    }

    public function findByGroupId(int $groupId): array
    {
        // Build query with proper table name handling
        if (isset($this->tableNameService)) {
            $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
            $query = "SELECT zg.*, d.name as zone_name, d.type as zone_type
                      FROM zones_groups zg
                      LEFT JOIN $domainsTable d ON zg.zone_id = d.id
                      WHERE zg.group_id = :group_id
                      ORDER BY zg.assigned_at DESC";
        } else {
            // Fallback without zone details if config not available
            $query = "SELECT * FROM zones_groups WHERE group_id = :group_id ORDER BY assigned_at DESC";
        }

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
            $query = "SELECT * FROM zones_groups WHERE zone_id = :zone_id AND group_id = :group_id";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':zone_id' => $domainId, ':group_id' => $groupId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $this->mapRowToEntity($row);
        }

        $query = "INSERT INTO zones_groups (zone_id, group_id, assigned_at)
                  VALUES (:zone_id, :group_id, CURRENT_TIMESTAMP)";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':zone_id' => $domainId,
            ':group_id' => $groupId
        ]);

        $id = (int)$this->db->lastInsertId();

        return new ZoneGroup($id, $domainId, $groupId, null, null, null, null);
    }

    public function remove(int $domainId, int $groupId): bool
    {
        $query = "DELETE FROM zones_groups WHERE zone_id = :zone_id AND group_id = :group_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':zone_id' => $domainId,
            ':group_id' => $groupId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function exists(int $domainId, int $groupId): bool
    {
        $query = "SELECT COUNT(*) FROM zones_groups WHERE zone_id = :zone_id AND group_id = :group_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':zone_id' => $domainId,
            ':group_id' => $groupId
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function mapRowToEntity(array $row): ZoneGroup
    {
        return new ZoneGroup(
            (int)$row['id'],
            (int)$row['zone_id'],
            (int)$row['group_id'],
            isset($row['zone_templ_id']) && $row['zone_templ_id'] !== null ? (int)$row['zone_templ_id'] : null,
            $row['assigned_at'] ?? null,
            $row['zone_name'] ?? null,
            $row['zone_type'] ?? null
        );
    }
}
