<?php

require_once('util/PoweradminUtil.class.php');
require_once('Rfc.class.php');
require_once('Record.class.php');
require_once('RfcManager.class.php');

class RfcResolver
{
    /**
     * @var PDOLayer db A connection to the database
     */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function build_rfcs_from_db()
    {
        $all_rfcs = $this->get_all_rfcs_from_db();
        return $this->build_rfcs($all_rfcs);
    }

    public function get_index_menu()
    {
        return self::get_menu_entry('<li class="menuitem"><a href="list_rfc.php">', '</a></li>');
    }

    public function get_header_menu()
    {
        return self::get_menu_entry(' <span class="menuitem"><a href="list_rfc.php">', '</a></span>');
    }

    ###########################################################################
    # PRIVATE FUNCTIONS

    private static function get_action($prior, $after)
    {
        if($prior && $after) {
            return 'edit';
        } elseif ($prior) {
            return 'delete';
        } elseif ($after) {
            return 'insert';
        } elseif (!$prior && !$after) {
            return 'zone_delete';
        }
        return null; # Unreachable
    }

    /**
     * @param String $before The html to put before the content
     * @param String $after The html to put after the content
     * @return String A string like this: <code>$before . $menu_entry . $after</code>
     */
    private function get_menu_entry($before = '', $after = '')
    {
        if(!RfcPermissions::can_view_rfcs()) {
            return '';
        }

        $rfc_manager = new RfcManager($this->db);
        $own_active = $rfc_manager->get_own_active_rfcs_count();
        $other_active = $rfc_manager->get_other_active_rfcs_count();

        $menu = $before;
        $menu .= _('Manage RFCs');

        $tag_start = $tag_end = '';
        if($own_active + $other_active > 0) {
            $tag_start = '<b>';
            $tag_end = '</b>';
        }

        $menu .= $tag_start . " (" . $own_active . " / " . $other_active . ") " . $tag_end;
        $menu .= $after;

        return $menu;
    }

    /**
     * @return array
     */
    private function get_all_rfcs_from_db()
    {
        $query = "
            SELECT
                r.id                 AS rfc_id,
                r.timestamp          AS rfc_timestamp,
                r.initiator          AS rfc_initiator,
                c.zone               AS zone_id,
                c.serial             AS change_based_on_serial,
                d.name               AS zone_name,
                d_before.id          AS prior_id,
                d_before.domain_id   AS prior_domain_id,
                d_before.name        AS prior_name,
                d_before.type        AS prior_type,
                d_before.content     AS prior_content,
                d_before.ttl         AS prior_ttl,
                d_before.prio        AS prior_prio,
                d_before.change_date AS prior_change_date,
                d_after.id           AS after_id,
                d_after.domain_id    AS after_domain_id,
                d_after.name         AS after_name,
                d_after.type         AS after_type,
                d_after.content      AS after_content,
                d_after.ttl          AS after_ttl,
                d_after.prio         AS after_prio,
                d_after.change_date  AS after_change_date
            FROM
                rfc r
                LEFT JOIN rfc_change c
                    ON r.id = c.rfc
                LEFT JOIN rfc_data d_before
                    ON c.prior = d_before.id
                LEFT JOIN rfc_data d_after
                    ON c.after = d_after.id
                JOIN domains d
                    ON c.zone = d.id
            ;";
        $stmt = $this->db->prepare($query);
        $success = $stmt->execute();
        $rfc_dump = $stmt->fetchAll(PDO::FETCH_ASSOC);

        # Decide between active and expired RFCs
        $active_rfcs = array();
        $expired_rfcs = array();
        $serial_cache = array();
        foreach ($rfc_dump as $row) {

            # Read zone
            $zone_id = $row['zone_id'];

            # Cache serials for less queries
            $serial_in_db = null;
            if ($serial_cache[$zone_id]) {
                $serial_in_db = $serial_cache[$zone_id];
            } else {
                $serial_in_db = get_serial_by_zid($zone_id);
                $serial_cache[$zone_id] = $serial_in_db;
            }

            # Make sure the rfc is based on the current version
            $serial_in_rfc_change = $row['change_based_on_serial'];
            if ($serial_in_db != $serial_in_rfc_change) {
                $row['rfc_expired'] = true;
                $expired_rfcs[] = $row;
            } else {
                $row['rfc_expired'] = false;
                $active_rfcs[] = $row;
            }
        }

        $all_rfcs = array_merge($active_rfcs, $expired_rfcs);
        return $all_rfcs;
    }

    private function build_rfcs($all_rfcs)
    {
        $rfcs_objs = array();
        foreach ($all_rfcs as $rfc_arr) {
            $rfc_id = $rfc_arr['rfc_id'];

            # Insert only if nonexistent
            if (!$rfcs_objs[$rfc_id]) {
                $rfcs_objs[$rfc_id] = RfcBuilder::make()
                    ->id($rfc_id)
                    ->expired($rfc_arr['rfc_expired'])
                    ->initiator($rfc_arr['rfc_initiator'])
                    ->timestamp($rfc_arr['rfc_timestamp'])
                    ->build();
            }
            $rfc_obj = $rfcs_objs[$rfc_id];

            $prior = $after = null;
            if($rfc_arr['prior_id']) {
                $prior = RecordBuilder::make(
                    $rfc_arr['prior_id'],
                    $rfc_arr['prior_domain_id'],
                    $rfc_arr['prior_name'],
                    $rfc_arr['prior_type'],
                    $rfc_arr['prior_content'],
                    $rfc_arr['prior_prio'],
                    $rfc_arr['prior_ttl'],
                    $rfc_arr['prior_change_date']
                );
            }
            if($rfc_arr['after_id']) {
                $after = RecordBuilder::make(
                    $rfc_arr['after_id'],
                    $rfc_arr['after_domain_id'],
                    $rfc_arr['after_name'],
                    $rfc_arr['after_type'],
                    $rfc_arr['after_content'],
                    $rfc_arr['after_prio'],
                    $rfc_arr['after_ttl'],
                    $rfc_arr['after_change_date']
                );
            }

            $action = self::get_action($prior, $after);
            $zone_id = $rfc_arr['zone_id'];
            $change_based_on_serial = $rfc_arr['change_based_on_serial'];
            switch($action) {
                case 'edit':
                    $rfc_obj->add_change($zone_id, $change_based_on_serial, $prior, $after);
                    break;

                case 'delete':
                    $rfc_obj->add_delete($zone_id, $change_based_on_serial, $prior);
                    break;

                case 'insert':
                    $rfc_obj->add_create($zone_id, $change_based_on_serial, $after);
                    break;

                case 'zone_delete':
                    $rfc_obj->add_delete_domain($zone_id, $change_based_on_serial);
                    break;

                default:
                    break;
            }
        }
        return $rfcs_objs;
    }
}
