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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Database\DbCompat;

class DbZoneLogger
{
    private PDO $db;
    private ConfigurationManager $config;
    private ?DnsBackendProvider $backendProvider;

    public function __construct($db, ?DnsBackendProvider $backendProvider = null)
    {
        $this->db = $db;
        $this->config = ConfigurationManager::getInstance();
        $this->config->initialize();
        $this->backendProvider = $backendProvider;
    }

    private function isApiBackend(): bool
    {
        return $this->backendProvider !== null && $this->backendProvider->isApiBackend();
    }

    public function doLog($msg, $zone_id, $priority): void
    {
        $stmt = $this->db->prepare('INSERT INTO log_zones (zone_id, event, priority) VALUES (:zone_id, :msg, :priority)');
        $stmt->execute([
            ':msg' => $msg,
            ':zone_id' => $zone_id,
            ':priority' => $priority,
        ]);
    }

    public function countAllLogs()
    {
        $stmt = $this->db->query("SELECT count(*) AS number_of_logs FROM log_zones");
        return $stmt->fetch()['number_of_logs'];
    }

    public function countLogsByDomain($domain)
    {
        if ($this->isApiBackend()) {
            $stmt = $this->db->prepare("
                SELECT count(zones.id) as number_of_logs
                FROM log_zones
                INNER JOIN zones ON COALESCE(zones.domain_id, zones.id) = log_zones.zone_id
                WHERE zones.zone_name IS NOT NULL AND zones.zone_name LIKE :search_by
            ");
        } else {
            $pdns_db_name = $this->config->get('database', 'pdns_db_name');
            $domains_table = $pdns_db_name ? "$pdns_db_name.domains" : "domains";

            $stmt = $this->db->prepare("
                SELECT count($domains_table.id) as number_of_logs
                FROM log_zones
                INNER JOIN $domains_table ON $domains_table.id = log_zones.zone_id
                WHERE $domains_table.name LIKE :search_by
            ");
        }

        $name = "%$domain%";
        $stmt->execute(['search_by' => $name]);
        return $stmt->fetch()['number_of_logs'];
    }

    public function getAllLogs($limit, $offset): array
    {
        $stmt = $this->db->prepare("
                    SELECT * FROM log_zones
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

    public function getLogsForDomain($domain, $limit, $offset): array
    {
        if (!($this->checkIfDomainExist($domain))) {
            return array();
        }

        if ($this->isApiBackend()) {
            $stmt = $this->db->prepare("
                SELECT log_zones.id, log_zones.event, log_zones.created_at, zones.zone_name as name
                FROM log_zones
                INNER JOIN zones ON COALESCE(zones.domain_id, zones.id) = log_zones.zone_id
                WHERE zones.zone_name IS NOT NULL AND zones.zone_name LIKE :search_by
                ORDER BY log_zones.created_at DESC
                LIMIT :limit
                OFFSET :offset");
        } else {
            $pdns_db_name = $this->config->get('database', 'pdns_db_name');
            $domains_table = $pdns_db_name ? "$pdns_db_name.domains" : "domains";

            $stmt = $this->db->prepare("
                SELECT log_zones.id, log_zones.event, log_zones.created_at, $domains_table.name
                FROM log_zones
                INNER JOIN $domains_table ON $domains_table.id = log_zones.zone_id
                WHERE $domains_table.name LIKE :search_by
                ORDER BY log_zones.created_at DESC
                LIMIT :limit
                OFFSET :offset");
        }

        $domain = "%$domain%";
        $stmt->bindValue(':search_by', $domain, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $records = $stmt->fetchAll();
        return $this->processFetchedLogs($records);
    }

    public function checkIfDomainExist($domain_searched): bool
    {
        if ($domain_searched == "") {
            return false;
        }

        $backendProvider = $this->backendProvider ?? \Poweradmin\Application\Service\DnsBackendProviderFactory::create($this->db, $this->config);
        $repositoryFactory = new \Poweradmin\Application\Service\RepositoryFactory($this->db, $this->config, $backendProvider);
        $domainRepository = $repositoryFactory->createDomainRepository();
        $zones = $domainRepository->getZones('all');
        foreach ($zones as $zone) {
            if (str_contains($zone['name'], $domain_searched)) {
                return true;
            }
        }
        return false;
    }

    public function getDistinctOperations(): array
    {
        return [
            'add_record',
            'add_zone',
            'api_add_record',
            'api_add_zone',
            'api_delete_record',
            'api_delete_zone',
            'api_edit_record',
            'delete_record',
            'delete_zone',
            'dnssec_add_key',
            'dnssec_delete_key',
            'dnssec_sign_zone',
            'dnssec_toggle_key',
            'dnssec_unsign_zone',
            'edit_record',
            'edit_zone_metadata',
            'unlink_zone_template',
            'zone_group_add',
            'zone_group_remove',
            'zone_import',
            'zone_owner_add',
            'zone_owner_remove',
        ];
    }

    public function getDistinctUsers(): array
    {
        $stmt = $this->db->query("SELECT DISTINCT username FROM users ORDER BY username");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Distinct usernames that appear inside log_zones events for the given zone IDs.
     * Empty zone list short-circuits to [] to avoid IN ().
     *
     * @param int[] $zoneIds Domain IDs to scope the search to
     * @return string[]
     */
    public function getDistinctUsersForZones(array $zoneIds): array
    {
        if (empty($zoneIds)) {
            return [];
        }

        $values = array_values($zoneIds);
        $placeholders = [];
        foreach ($values as $i => $_zid) {
            $placeholders[] = ':z' . $i;
        }

        $dbType = $this->config->get('database', 'type', 'mysql');
        $userPattern = DbCompat::concat($dbType, ["'%user:'", 'u.username', "' %'"]);

        $sql = "SELECT DISTINCT u.username FROM users u
                WHERE EXISTS (
                    SELECT 1 FROM log_zones lz
                    WHERE lz.zone_id IN (" . implode(', ', $placeholders) . ")
                      AND lz.event LIKE " . $userPattern . "
                )
                ORDER BY u.username";

        $stmt = $this->db->prepare($sql);
        foreach ($values as $i => $zid) {
            $stmt->bindValue(':z' . $i, (int) $zid, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param int[]|null $zoneIds Optional whitelist of log_zones.zone_id values.
     *   null = no filter (admin); [] = no zones (returns 0); non-empty = restrict to listed IDs.
     */
    public function countFilteredLogs(array $filters, ?array $zoneIds = null): int
    {
        if ($zoneIds !== null && empty($zoneIds)) {
            return 0;
        }

        $query = "SELECT COUNT(*) AS number_of_logs FROM log_zones";
        $conditions = [];
        $params = [];

        $this->buildFilterConditions($filters, $query, $conditions, $params, $zoneIds);

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

    /**
     * @param int[]|null $zoneIds Optional whitelist of log_zones.zone_id values.
     *   null = no filter (admin); [] = no zones (returns []); non-empty = restrict to listed IDs.
     */
    public function getFilteredLogs(array $filters, int $limit, int $offset, ?array $zoneIds = null): array
    {
        if ($zoneIds !== null && empty($zoneIds)) {
            return [];
        }

        $query = "SELECT log_zones.id, log_zones.event, log_zones.created_at FROM log_zones";
        $conditions = [];
        $params = [];

        $this->buildFilterConditions($filters, $query, $conditions, $params, $zoneIds);

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        $query .= " ORDER BY log_zones.created_at DESC LIMIT :limit OFFSET :offset";

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

    private function buildFilterConditions(array $filters, string &$query, array &$conditions, array &$params, ?array $zoneIds = null): void
    {
        if (!empty($filters['name'])) {
            if ($this->isApiBackend()) {
                $query = str_replace('FROM log_zones', 'FROM log_zones INNER JOIN zones ON COALESCE(zones.domain_id, zones.id) = log_zones.zone_id', $query);
                $conditions[] = "zones.zone_name LIKE :search_by";
            } else {
                $pdns_db_name = $this->config->get('database', 'pdns_db_name');
                $domains_table = $pdns_db_name ? "$pdns_db_name.domains" : "domains";
                $query = str_replace('FROM log_zones', "FROM log_zones INNER JOIN $domains_table ON $domains_table.id = log_zones.zone_id", $query);
                $conditions[] = "$domains_table.name LIKE :search_by";
            }
            $params[':search_by'] = ["%" . $filters['name'] . "%", PDO::PARAM_STR];
        }

        if (!empty($filters['operation'])) {
            $conditions[] = "log_zones.event LIKE :operation";
            $params[':operation'] = ["%operation:" . $filters['operation'] . " %", PDO::PARAM_STR];
        }

        if (!empty($filters['user'])) {
            $conditions[] = "log_zones.event LIKE :user_filter";
            $params[':user_filter'] = ["%user:" . $filters['user'] . " %", PDO::PARAM_STR];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = "log_zones.created_at >= :date_from";
            $params[':date_from'] = [$filters['date_from'] . " 00:00:00", PDO::PARAM_STR];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = "log_zones.created_at <= :date_to";
            $params[':date_to'] = [$filters['date_to'] . " 23:59:59", PDO::PARAM_STR];
        }

        if ($zoneIds !== null && !empty($zoneIds)) {
            $placeholders = [];
            foreach (array_values($zoneIds) as $i => $zid) {
                $key = ':zone_owner_id_' . $i;
                $placeholders[] = $key;
                $params[$key] = [(int) $zid, PDO::PARAM_INT];
            }
            $conditions[] = 'log_zones.zone_id IN (' . implode(', ', $placeholders) . ')';
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
