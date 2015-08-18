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
            # Delete rfc_data
            $delete1_stmt = $this->db->prepare('DELETE FROM rfc_data WHERE id IN (:prior, :after)');
            $delete1_stmt->bindParam(':prior', $change['prior']);
            $delete1_stmt->bindParam(':after', $change['after']);
            $delete1_success = $delete1_stmt->execute();

            # Delete rfc_change
            $delete2_stmt = $this->db->prepare('DELETE FROM rfc_change WHERE id = :rfc_change_id');
            $delete2_stmt->bindParam(':rfc_change_id', $change['id']);
            $delete2_success = $delete2_stmt->execute();
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
}
