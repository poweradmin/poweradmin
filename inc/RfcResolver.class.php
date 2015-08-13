<?php

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

        $username = PoweradminUtil::get_username();
        $own_active = $this->get_own_active_rfcs($username);
        $other_active = $this->get_other_active_rfcs($username);

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

    public function get_index_menu()
    {
        return self::get_menu_entry('<li class="menuitem"><a href="list_rfc.php">', '</a></li>');
    }

    public function get_header_menu()
    {
        return self::get_menu_entry(' <span class="menuitem"><a href="list_rfc.php">', '</a></span>');
    }

    # TODO: Fix duplication in get_own_active_rfcs / get_other_active_rfcs. There is only 1 char difference!

    public function get_own_active_rfcs($user)
    {
        $query = "
SELECT
    r.id as 'rfc', c.zone, c.serial
FROM
    rfc r
INNER JOIN
	rfc_change c ON r.id   = c.rfc
WHERE r.initiator = :user
;";
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

    public function get_other_active_rfcs($user)
    {
        $query = "
SELECT
    r.id as 'rfc', c.zone, c.serial
FROM
    rfc r
INNER JOIN
	rfc_change c ON r.id   = c.rfc
WHERE r.initiator != :user
;";
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
