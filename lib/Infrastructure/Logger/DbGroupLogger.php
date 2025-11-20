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

namespace Poweradmin\Infrastructure\Logger;

use PDO;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;

class DbGroupLogger
{
    private PDOCommon $db;
    private ConfigurationManager $config;

    public function __construct($db)
    {
        $this->db = $db;
        $this->config = ConfigurationManager::getInstance();
        $this->config->initialize();
    }

    public function doLog($msg, $group_id, $priority): void
    {
        $dblog_use = $this->config->get('logging', 'database_enabled');

        if (!$dblog_use) {
            return;
        }

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
