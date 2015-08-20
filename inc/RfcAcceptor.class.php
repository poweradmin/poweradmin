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

            # Yes you shouldn't do that, yes, its ugly. But here you go:
            switch($change_type) {
                case 'record_insert':
                    $action = 'add_record.php';
                    $_GET['id'] = $change->getZone();
                    $_POST['ttl'] = $change->getAfter()->getTtl();
                    $_POST['prio'] = $change->getAfter()->getPrio();
                    $_POST['name'] = $change->getAfter()->getName();
                    $_POST['type'] = $change->getAfter()->getType();
                    $_POST['content'] = $change->getAfter()->getContent();
                    $_POST['commit'] = 'Add record';
                    $_POST['rfc_commit'] = true;

                    require($action);
                    break;

                case 'record_edit':
                    $action = 'edit_record.php';
                    $_GET['id'] = $change->getAffectedRecordId();
                    $_POST['rid'] = $change->getAffectedRecordId();
                    $_POST['zid'] = $change->getAfter()->getZone();
                    $_POST['name'] = $change->getAfter()->getName();
                    $_POST['type'] = $change->getAfter()->getType();
                    $_POST['content'] = $change->getAfter()->getContent();
                    $_POST['prio'] = $change->getAfter()->getPrio();
                    $_POST['ttl'] = $change->getAfter()->getTtl();
                    $_POST['commit'] = 'Edit record';

                    require($action);
                    break;

                case 'record_delete':
                    $action = 'delete_record.php';
                    $_GET['id'] = $change->getAffectedRecordId();
                    $_GET['confirm'] = 1;
                    $_POST['rfc_commit'] = true;

                    require($action);
                    break;

                case 'zone_delete':
                    $action = 'delete_domain.php';
                    $_GET['id'] = $change->getZone();
                    $_GET['confirm'] = 1;

                    require($action);
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
