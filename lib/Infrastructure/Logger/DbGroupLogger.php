<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Infrastructure\Logger;

use PDO;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;

class DbGroupLogger
{
    private PDO $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function doLog($msg, $group_id, $priority): void
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO log_groups (group_id, event, priority) VALUES (:group_id, :msg, :priority)');
            $stmt->execute([
                ':msg' => $msg,
                ':group_id' => $group_id,
                ':priority' => $priority,
            ]);
        } catch (\PDOException $e) {
            // Silently fail if table doesn't exist yet (during upgrade before migration)
            // This prevents breaking group operations when log_groups table hasn't been created
            return;
        }
    }

    public function countAllLogs()
    {
        $stmt = $this->db->query("SELECT count(*) AS number_of_logs FROM log_groups");
        return $stmt->fetch()['number_of_logs'];
    }

    public function countLogsByGroup($groupName)
    {
        $stmt = $this->db->prepare("
                    SELECT count(user_groups.id) as number_of_logs
                    FROM log_groups
                    INNER JOIN user_groups
                    ON user_groups.id = log_groups.group_id
                    WHERE user_groups.name LIKE :search_by
        ");
        $name = "%$groupName%";
        $stmt->execute(['search_by' => $name]);
        return $stmt->fetch()['number_of_logs'];
    }

    public function getAllLogs($limit, $offset): array
    {
        $stmt = $this->db->prepare("
                    SELECT * FROM log_groups
                    ORDER BY created_at DESC
                    LIMIT :limit
                    OFFSET :offset
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $records = $stmt->fetchAll();
        return $this->processFetchedLogs($records);
    }

    public function getLogsForGroup($groupName, $limit, $offset): array
    {
        if (!($this->checkIfGroupExist($groupName))) {
            return array();
        }

        $stmt = $this->db->prepare("
            SELECT log_groups.id, log_groups.event, log_groups.created_at, user_groups.name FROM log_groups
            INNER JOIN user_groups ON user_groups.id = log_groups.group_id
            WHERE user_groups.name LIKE :search_by
            ORDER BY log_groups.created_at DESC
            LIMIT :limit
            OFFSET :offset");

        $groupName = "%$groupName%";
        $stmt->bindValue(':search_by', $groupName, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $records = $stmt->fetchAll();
        return $this->processFetchedLogs($records);
    }

    public function checkIfGroupExist($groupSearched): bool
    {
        if ($groupSearched == "") {
            return false;
        }

        $groupRepository = new DbUserGroupRepository($this->db);
        $groups = $groupRepository->findAll();
        foreach ($groups as $group) {
            if (str_contains($group->getName(), $groupSearched)) {
                return true;
            }
        }
        return false;
    }

    public function getDistinctEventTypes(): array
    {
        return [
            'add_members',
            'add_zones',
            'create_group',
            'delete_group',
            'edit_group',
            'remove_members',
            'remove_zones',
        ];
    }

    public function countFilteredLogs(array $filters): int
    {
        $query = "SELECT COUNT(*) AS number_of_logs FROM log_groups";
        $conditions = [];
        $params = [];

        $this->buildFilterConditions($filters, $query, $conditions, $params);

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value[0], $value[1]);
        }
        $stmt->execute();
        return (int) $stmt->fetch()['number_of_logs'];
    }

    public function getFilteredLogs(array $filters, int $limit, int $offset): array
    {
        $query = "SELECT log_groups.* FROM log_groups";
        $conditions = [];
        $params = [];

        $this->buildFilterConditions($filters, $query, $conditions, $params);

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        $query .= " ORDER BY log_groups.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value[0], $value[1]);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $records = $stmt->fetchAll();
        return $this->processFetchedLogs($records);
    }

    private function buildFilterConditions(array $filters, string &$query, array &$conditions, array &$params): void
    {
        if (!empty($filters['name'])) {
            $query = str_replace('FROM log_groups', 'FROM log_groups INNER JOIN user_groups ON user_groups.id = log_groups.group_id', $query);
            $conditions[] = "user_groups.name LIKE :search_by";
            $params[':search_by'] = ["%" . $filters['name'] . "%", PDO::PARAM_STR];
        }

        if (!empty($filters['event_type'])) {
            $typePatterns = [
                'create_group' => '%operation:create_group%',
                'edit_group' => '%operation:edit_group%',
                'delete_group' => '%operation:delete_group%',
                'add_members' => '%operation:add_members%',
                'remove_members' => '%operation:remove_members%',
                'add_zones' => '%operation:add_zones%',
                'remove_zones' => '%operation:remove_zones%',
            ];
            if (isset($typePatterns[$filters['event_type']])) {
                $conditions[] = "log_groups.event LIKE :event_type";
                $params[':event_type'] = [$typePatterns[$filters['event_type']], PDO::PARAM_STR];
            }
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = "log_groups.created_at >= :date_from";
            $params[':date_from'] = [$filters['date_from'] . " 00:00:00", PDO::PARAM_STR];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = "log_groups.created_at <= :date_to";
            $params[':date_to'] = [$filters['date_to'] . " 23:59:59", PDO::PARAM_STR];
        }
    }

    private function processDetails($event): string
    {
        return strtr($event, [" " => "<br>", ":" => ": "]);
    }

    private function processFetchedLogs(array $records): array
    {
        foreach ($records as $key => $record) {
            $records[$key]['details'] = $this->processDetails($record['event']);
        }

        return $records;
    }
}
