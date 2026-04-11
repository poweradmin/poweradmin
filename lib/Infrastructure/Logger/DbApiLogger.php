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

class DbApiLogger
{
    private PDO $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function doLog($msg, $priority): void
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO log_api (event, priority) VALUES (:msg, :priority)');
            $stmt->execute([
                ':msg' => $msg,
                ':priority' => $priority,
            ]);
        } catch (\PDOException $e) {
            // Silently fail if table doesn't exist yet (during upgrade before migration)
            return;
        }
    }

    public function getDistinctEventTypes(): array
    {
        return [
            'api_key_create',
            'api_key_delete',
            'api_key_edit',
            'api_key_regenerate',
            'api_key_toggle',
        ];
    }

    public function getDistinctUsers(): array
    {
        $stmt = $this->db->query("SELECT DISTINCT username FROM users ORDER BY username");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function countFilteredLogs(array $filters): int
    {
        $query = "SELECT COUNT(*) AS number_of_logs FROM log_api";
        $conditions = [];
        $params = [];

        $this->buildFilterConditions($filters, $conditions, $params);

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
        $query = "SELECT * FROM log_api";
        $conditions = [];
        $params = [];

        $this->buildFilterConditions($filters, $conditions, $params);

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value[0], $value[1]);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function buildFilterConditions(array $filters, array &$conditions, array &$params): void
    {
        if (!empty($filters['name'])) {
            $conditions[] = "log_api.event LIKE :search_by";
            $params[':search_by'] = ["%'" . $filters['name'] . "'%", PDO::PARAM_STR];
        }

        if (!empty($filters['event_type'])) {
            $typePatterns = [
                'api_key_create' => '%operation:api_key_create%',
                'api_key_edit' => '%operation:api_key_edit%',
                'api_key_delete' => '%operation:api_key_delete%',
                'api_key_regenerate' => '%operation:api_key_regenerate%',
                'api_key_toggle' => '%operation:api_key_toggle%',
            ];
            if (isset($typePatterns[$filters['event_type']])) {
                $conditions[] = "log_api.event LIKE :event_type";
                $params[':event_type'] = [$typePatterns[$filters['event_type']], PDO::PARAM_STR];
            }
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = "log_api.created_at >= :date_from";
            $params[':date_from'] = [$filters['date_from'] . " 00:00:00", PDO::PARAM_STR];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = "log_api.created_at <= :date_to";
            $params[':date_to'] = [$filters['date_to'] . " 23:59:59", PDO::PARAM_STR];
        }
    }
}
