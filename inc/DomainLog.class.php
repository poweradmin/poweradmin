<?php

require_once('TimeHelper.class.php');

class DomainLog
{

    private $db;

    public static function with_db($db)
    {
        $instance = new DomainLog();
        $instance->set_database($db);
        return $instance;
    }

    public function delete_domain($domain_id)
    {
        $domain_log_type = $this->getLogType('domain_delete');
        $now = $this->getDate();
        $user = $this->getUser();
        $domain_name = $this->db->queryOne("SELECT name FROM domains WHERE id = " . $this->db->quote($domain_id));

        // TODO: Log approving user (col                                                                v here)
        $log_delete_domain = "INSERT INTO log_domains (log_domains_type_id, domain_name, timestamp, user) VALUES ("
            . $this->db->quote($domain_log_type, 'integer') . ","
            . $this->db->quote($domain_name, 'text') . ","
            . $this->db->quote($now, 'text') . ","
            . $this->db->quote($user, 'text') . ")";
        $this->db->exec($log_delete_domain);
    }

    private function getLogType($type_name)
    {
        return $this->db->queryOne("SELECT id FROM log_domains_type WHERE name = '" . $type_name . "'");
    }

    private function getUser()
    {
        // TODO: Move to utility class
        return $_SESSION['userlogin'];
    }

    private function getDate()
    {
        $th = new TimeHelper();
        return $th->now()->format($th->format);
    }

    private function set_database($db)
    {
        $this->db = $db;
    }
}
