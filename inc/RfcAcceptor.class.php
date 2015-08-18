<?php

$help = "
add_record.php
  GET: id (zone_id)
 POST: ttl, prio, name, type, content, commit='_isset'

edit_record.php
  GET: id (record_id)
 POST: rid, zid, name, type, content, prio, ttl, commit='_isset'

delete_record.php
  GET: id (record_id), confirm='1'
 POST:

delete_domain.php
  GET: id (zone_id), confirm='1'
 POST:
";

class RfcAcceptor
{
    private $db;
    private $manager;

    /**
     * RfcAcceptor constructor.
     * @param PDOLayer $db
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->manager = new RfcManager($db);
    }

    /**
     * @param Rfc $rfc
     * @return bool True on success. False otherwise.
     */
    public function accept($rfc)
    {
        $success = $this->apply_changes($rfc);
        if($success === true) {
            $this->manager->delete_rfc($rfc->getId());
            return true;
        }

        return false;
    }

    /**
     * @param Rfc $rfc The RFC whose changes will be applied in the database.
     * @return bool if the
     */
    private function apply_changes($rfc)
    {
        $success = true;
        $changes = $rfc->getChanges();
        foreach ($changes as $change) {
            $change_type = self::get_action_type($change->getPrior(), $change->getAfter());

            switch($change_type) {
                case 'record_edit':
                    $method = 'POST';
                    $action = 'add_record.php';
                    // TODO 10: CURL IT!
                    break;

                case 'record_delete':
                    $method = 'GET';
                    $action = 'edit_record.php';
                    // TODO 10: CURL IT!
                    break;

                case 'record_insert':
                    $method = 'POST';
                    $action = 'delete_record.php';
                    // TODO 10: CURL IT!
                    break;

                case 'zone_delete':
                    $method = 'GET';
                    $action = 'delete_domain.php';
                    // TODO 10: CURL IT!
                    break;
            }
        }
        return $success;
    }


    private function get_action_type($prior, $after)
    {
        if ($prior && $after) {
            return 'record_edit';
        } elseif ($prior) {
            return 'record_delete';
        } elseif ($after) {
            return 'record_insert';
        } else {
            return 'zone_delete';
        }
    }
}
