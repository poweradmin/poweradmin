<?php

class RfcManager
{
    private $db;

    /**
     * RfcManager constructor.
     * @param PDOLayer $db A connection to the database
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    public function delete_rfc($rfc_id)
    {
        # Get data
        $select = $this->db->prepare('SELECT id, prior, after, rfc from rfc_change WHERE rfc = :rfc_id');
        $select->bindParam(':rfc_id', $rfc_id);
        $select_success = $select->execute();
        $select_result = $select->fetchAll(PDO::FETCH_ASSOC);

        foreach ($select_result as $change) {
            # Delete rfc_change
            $this->delete_rfc_change($change['id']);

            # Delete rfc_data
            $this->delete_rfc_data($change['prior']);
            $this->delete_rfc_data($change['after']);

        }

        # Delete rfc
        $delete3_stmt = $this->db->prepare('DELETE FROM rfc WHERE id = :rfc_id');
        $delete3_stmt->bindParam(':rfc_id', $rfc_id);
        $delete3_success = $delete3_stmt->execute();
    }

    public function get_own_active_rfcs_count()
    {
        $where = 'WHERE r.initiator = :user';
        return self::get_active_rfcs_count($where);
    }

    public function get_other_active_rfcs_count()
    {
        $where = 'WHERE r.initiator != :user';
        return self::get_active_rfcs_count($where);
    }

    public function zone_has_changes($zone_id)
    {
        # Get data
        $select = $this->db->prepare("SELECT count(*) as 'count' FROM powerdns.rfc_change WHERE zone = :zone_id");
        $select->bindParam(':zone_id', $zone_id, PDO::PARAM_INT);
        $select->execute();
        $result = $select->fetch(PDO::FETCH_ASSOC);
        $count = $result['count'];

        if(ctype_digit($count) && $count > 0) {
            return true;
        }
        return false;
    }
    ###########################################################################
    # PRIVATE FUNCTIONS

    private function get_active_rfcs_count($where)
    {
        $user = PoweradminUtil::get_username();
        $query = "
SELECT
    r.id AS 'rfc',
    c.zone,
    c.serial
FROM
    rfc r
    INNER JOIN rfc_change c
        ON r.id = c.rfc " . $where . ";";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":user", $user, PDO::PARAM_STR);
        $success = $stmt->execute();

        $rfcs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        # Filter active
        $active_rfcs = array();
        foreach ($rfcs as $rfc) {
            $current_serial = get_serial_by_zid($rfc['zone']);
            if($current_serial == $rfc['serial']) { # Is it still valid (based on current zone)?
                $active_rfcs[] = $rfc['rfc'];
            }
        }

        return count(array_unique($active_rfcs));
    }

    private function delete_rfc_data($entry)
    {
        if(!$entry) { return; }

        $delete_stmt = $this->db->prepare('DELETE FROM rfc_data WHERE id = :entry');
        $delete_stmt->bindParam(':entry', $entry, PDO::PARAM_INT);
        $delete_success = $delete_stmt->execute();
    }

    private function delete_rfc_change($change_id)
    {
        $delete2_stmt = $this->db->prepare('DELETE FROM rfc_change WHERE id = :rfc_change_id');
        $delete2_stmt->bindParam(':rfc_change_id', $change_id);
        $delete2_success = $delete2_stmt->execute();
    }
}
