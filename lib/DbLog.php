<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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

namespace Poweradmin;

class DbLog
{
    public static function do_log($msg, $zone_id)
    {
        global $db;
        $stmt = $db->prepare(' insert into logs (zone_id, log) values (:zone_id, :msg)');
        $stmt->execute([
            ':msg' => $db->quote($msg, 'text'),
            ':zone_id' => $zone_id
        ]);
    }

    public static function count_all_logs()
    {
        global $db;
        $stmt = $db->query("SELECT count(*) as 'number_of_logs' FROM logs ");
        return $stmt->fetch()['number_of_logs'];
    }

    public static function count_logs_by_domain($domain)
    {
        global $db;
        $stmt = $db->prepare("
                    select count(domains.id) as 'number_of_logs' 
                    from logs 
                    inner join domains 
                    on domains.id = logs.zone_id 
                    where domains.name like :search_by "
        );
        $name = $domain;
        $name = "%$name%";
        $stmt->execute(['search_by' => $name]);
        return $stmt->fetch()['number_of_logs'];
    }

    public static function count_auth_logs()
    {
        global $db;
        $stmt = $db->query("SELECT count(*) as 'number_of_logs' FROM logs where zone_id is null ");
        return $stmt->fetch()['number_of_logs'];
    }

    public static function get_all_logs($limit, $offset)
    {
        global $db;
        $stmt = $db->prepare("
                    SELECT * FROM logs 
                    order by created_at desc 
                    LIMIT :limit 
                    OFFSET :offset 
                    ");

        $stmt->execute([
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $stmt->fetchAll();
    }

    public static function get_logs_for_domain($domain, $limit, $offset)
    {
        if (!(self::check_if_domain_exist($domain))) {
            return array();
        }

        global $db;
        $stmt = $db->prepare(
            "select 
            logs.log, logs.created_at, domains.name from logs 
            inner join domains on domains.id = logs.zone_id 
            where domains.name like :search_by 
            LIMIT :limit 
            OFFSET :offset"
        );

        $domain = "%$domain%";
        $stmt->execute([
            'search_by' => $domain,
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $stmt->fetchAll();
    }

    public static function check_if_domain_exist($domain_searched)
    {
        if ($domain_searched == "") {
            return false;
        }

        $zones = DnsRecord::get_zones('all');
        foreach ($zones as $zone) {
            if (strpos($zone['name'], $domain_searched) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function get_auth_logs($limit, $offset)
    {
        global $db;
        $stmt = $db->prepare("
                    SELECT * FROM logs 
                    where zone_id is null
                    order by created_at desc 
                    LIMIT :limit 
                    OFFSET :offset 
                    ");

        $stmt->execute([
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $stmt->fetchAll();
    }
}
