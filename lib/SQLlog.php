<?php
/**
 * created by bnch
 *
 */

namespace Poweradmin;

class SQLlog
{
    /**
     * write log to table `logs`
     */
    public static function do_log($msg, $zone_id)
    {
        global $db;
        $stmt = $db->prepare(' insert into logs (zone_id, log) values (:zone_id, :msg)');
        $stmt->execute([
            ':msg' => $db->quote($msg, 'text'),
            ':zone_id' => $zone_id
        ]);
    }

    /**
     *count all logs from table `logs`
     * @return number of all logs
     */
    public static function count_all_logs()
    {
        global $db;
        $stmt = $db->query("SELECT count(*) as 'number_of_logs' FROM logs ");
        return $stmt->fetch()['number_of_logs'];
    }

    /**
     * count logs by domain
     * @return number of logs for
     */
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

    /**
     * count auth logs
     * @return number of auth logs
     * */
    public static function count_auth_logs()
    {
        global $db;
        $stmt = $db->query("SELECT count(*) as 'number_of_logs' FROM logs where zone_id is null ");
        return $stmt->fetch()['number_of_logs'];
    }

    /**
     * get all logs
     * @return logs array
     * */
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


    /**
     * get logs for domain
     * @return logs array for domain
     */
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

    /**
     * check if searched string is in domain list
     * @return 0 - not found
     * @return 1 - string is in domain
     */
    public static function check_if_domain_exist($domain_searched)
    {
        $zones = DnsRecord::get_zones('all');
        foreach ($zones as $zone) {

            if (strpos($zone['name'], $domain_searched) !== false) {
                return 1;
            }
        }
        return 0;
    }

    /**
     * get auth logs
     * @return auth array logs
     */
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
