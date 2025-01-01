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

use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\AppConfiguration;

class DbZoneLogger
{
    private PDOLayer $db;
    private AppConfiguration $config;

    public function __construct($db) {
        $this->db = $db;
        $this->config = new AppConfiguration();
    }

    public function do_log($msg, $zone_id, $priority): void
    {
        $stmt = $this->db->prepare('INSERT INTO log_zones (zone_id, event, priority) VALUES (:zone_id, :msg, :priority)');
        $stmt->execute([
            ':msg' => $msg,
            ':zone_id' => $zone_id,
            ':priority' => $priority,
        ]);
    }

    public function count_all_logs()
    {
        $stmt = $this->db->query("SELECT count(*) AS number_of_logs FROM log_zones");
        return $stmt->fetch()['number_of_logs'];
    }

    public function count_logs_by_domain($domain)
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? "$pdns_db_name.domains" : "domains";

        $stmt = $this->db->prepare("
                    SELECT count($domains_table.id) as number_of_logs
                    FROM log_zones
                    INNER JOIN $domains_table 
                    ON $domains_table.id = log_zones.zone_id
                    WHERE $domains_table.name LIKE :search_by
        ");
        $name = "%$domain%";
        $stmt->execute(['search_by' => $name]);
        return $stmt->fetch()['number_of_logs'];
    }

    public function get_all_logs($limit, $offset): array
    {
        $stmt = $this->db->prepare("
                    SELECT * FROM log_zones
                    ORDER BY created_at DESC 
                    LIMIT :limit 
                    OFFSET :offset 
        ");

        $stmt->execute([
            'limit' => $limit,
            'offset' => $offset
        ]);

        $records = $stmt->fetchAll();
        return $this->processFetchedLogs($records);
    }

    public function get_logs_for_domain($domain, $limit, $offset): array
    {
        if (!($this->check_if_domain_exist($domain))) {
            return array();
        }

        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? "$pdns_db_name.domains" : "domains";

        $stmt = $this->db->prepare("
            SELECT log_zones.id, log_zones.event, log_zones.created_at, $domains_table.name FROM log_zones
            INNER JOIN $domains_table ON $domains_table.id = log_zones.zone_id 
            WHERE $domains_table.name LIKE :search_by
            LIMIT :limit 
            OFFSET :offset"
        );

        $domain = "%$domain%";
        $stmt->execute([
            'search_by' => $domain,
            'limit' => $limit,
            'offset' => $offset
        ]);

        $records = $stmt->fetchAll();
        return $this->processFetchedLogs($records);
    }

    public function check_if_domain_exist($domain_searched): bool
    {
        if ($domain_searched == "") {
            return false;
        }

        $dnsRecord = new DnsRecord($this->db, $this->config);
        $zones = $dnsRecord->get_zones('all');
        foreach ($zones as $zone) {
            if (str_contains($zone['name'], $domain_searched)) {
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
