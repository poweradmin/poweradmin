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
}
