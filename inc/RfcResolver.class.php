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

    // TODO: Please refactor
    public function build_tree()
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
        $active_rfcs = array();
        $expired_rfcs = array();

        # Filter active RFCs (with cache, yeah!)
        $serial_cache = array();
        foreach ($rfc_dump as $row) {

            # Read zone
            $zone_id = $row['zone_id'];

            # Cache serials for less queries
            $serial_in_db = null;
            if($serial_cache[$zone_id]) {
                $serial_in_db = $serial_cache[$zone_id];
            } else {
                $serial_in_db = get_serial_by_zid($zone_id);
                $serial_cache[$zone_id] = $serial_in_db;
            }

            # Make sure the rfc is based on the current version
            $serial_in_rfc_change = $row['change_based_on_serial'];
            if($serial_in_db != $serial_in_rfc_change) {
                $row['rfc_expired'] = true;
                $expired_rfcs[] = $row;
            } else {
                $row['rfc_expired'] = false;
                $active_rfcs[] = $row;
            }
        }

        $all_rfcs = array_merge($active_rfcs, $expired_rfcs);

        $rfcs = array();
        foreach ($all_rfcs as $rfc_rows) {
            $rfc_id = $rfc_rows['rfc_id'];

            $rfc = RfcBuilder::make()->initiator($rfc_rows['rfc_initiator'])->timestamp($rfc_rows['rfc_timestamp'])->build();
            $rfc->setId($rfc_id);
            $rfc->setExpired($rfc_rows['rfc_expired']);

            # Insert only if nonexistent
            if (!$rfcs[$rfc_id]) {
                $rfcs[$rfc_id] = $rfc;
            } else {
                # We already have this RFC. Just need to put the changes in $rfc
                $rfc = $rfcs[$rfc_id];
            }

            $prior = $after = null;
            if($rfc_rows['prior_id']) {
                $prior = RecordBuilder::make(
                    $rfc_rows['prior_id'],
                    $rfc_rows['prior_domain_id'],
                    $rfc_rows['prior_name'],
                    $rfc_rows['prior_type'],
                    $rfc_rows['prior_content'],
                    $rfc_rows['prior_prio'],
                    $rfc_rows['prior_ttl'],
                    $rfc_rows['prior_change_date']
                );
            }
            if($rfc_rows['after_id']) {
                $after = RecordBuilder::make(
                    $rfc_rows['after_id'],
                    $rfc_rows['after_domain_id'],
                    $rfc_rows['after_name'],
                    $rfc_rows['after_type'],
                    $rfc_rows['after_content'],
                    $rfc_rows['after_prio'],
                    $rfc_rows['after_ttl'],
                    $rfc_rows['after_change_date']
                );
            }
            # Find out what we are doing
            $action = null;
            if($prior && $after) { $action = 'edit'; }
            elseif ($prior) { $action = 'delete'; }
            elseif ($after) { $action = 'insert'; }
            else { $action = 'zone_delete'; }

            switch($action) {
                case 'edit':
                    $rfc->add_change($rfc_rows['zone_id'], $rfc_rows['change_based_on_serial'], $prior, $after);
                    break;

                case 'delete':
                    $rfc->add_delete($rfc_rows['zone_id'], $rfc_rows['change_based_on_serial'], $prior);
                    break;

                case 'insert':
                    $rfc->add_create($rfc_rows['zone_id'], $rfc_rows['change_based_on_serial'], $after);
                    break;

                case 'zone_delete':
                    $rfc->add_delete_domain($rfc_rows['zone_id'], $rfc_rows['change_based_on_serial']);
                    break;

                default:
                    break;
            }
        }
        return $rfcs;
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
}
