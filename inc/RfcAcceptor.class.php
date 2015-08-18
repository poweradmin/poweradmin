<?php

$help = "
add_record.php
  GET: id (zone_id)
 POST: ttl, prio, name, type, content, commit='_isset'

delete_domain.php
  GET: id (zone_id), confirm='1'
 POST:

delete_record.php
  GET: id (record_id), confirm='1'
 POST:

edit_record.php
  GET: id (record_id)
 POST: rid, zid, name, type, content, prio, ttl, commit='_isset'

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
     */
    public function accept($rfc)
    {
        $success = $this->apply_changes($rfc);
        if($success === true) {
            $this->manager->delete_rfc($rfc->getId());
        }
    }

    /**
     * @param Rfc $rfc The RFC whose changes will be applied in the database.
     * @return bool if the
     */
    private function apply_changes($rfc)
    {
        $success = true;



        return $success;
    }

}
