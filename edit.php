<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Script that handles editing of zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

global $pdnssec_use;

$zone_id = "-1";
if (isset($_GET['id']) && v_num($_GET['id'])) {
    $zone_id = $_GET['id'];
}

if ($zone_id == "-1") {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}

if (isset($_POST['commit'])) {
    $error = false;
    if (isset($_POST['record'])) {
        foreach ($_POST['record'] as $record) {
            $old_record_info = get_record_from_id($record['rid']);
            $edit_record = edit_record($record);
            if (false === $edit_record) {
                $error = true;
            } else {
               $new_record_info = get_record_from_id($record["rid"]);
               //Figure out if record was updated
               unset($new_record_info["change_date"]);
               unset($old_record_info["change_date"]);
               if ($new_record_info != $old_record_info){
                 //The record was changed, so log the edit_record operation
                 log_info(sprintf('client_ip:%s user:%s operation:edit_record'
                                  .' old_record_type:%s old_record:%s old_content:%s old_ttl:%s old_priority:%s'
                                  .' record_type:%s record:%s content:%s ttl:%s priority:%s',
                                  $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                              $old_record_info['type'], $old_record_info['name'],
                              $old_record_info['content'], $old_record_info['ttl'], $old_record_info['prio'],
                              $new_record_info['type'], $new_record_info['name'],
                              $new_record_info['content'], $new_record_info['ttl'], $new_record_info['prio']));
               }
            }
        }
    }

    edit_zone_comment($_GET['id'], $_POST['comment']);

    if (false === $error) {
        update_soa_serial($_GET['id']);
        success(SUC_ZONE_UPD);

        if ($pdnssec_use) {
            if (dnssec_rectify_zone($_GET['id'])) {
                success(SUC_EXEC_PDNSSEC_RECTIFY_ZONE);
            }
        }
    } else {
        error(ERR_ZONE_UPD);
    }
}

if (isset($_POST['save_as'])) {
    if (zone_templ_name_exists($_POST['templ_name'])) {
        error(ERR_ZONE_TEMPL_EXIST);
    } elseif ($_POST['templ_name'] == '') {
        error(ERR_ZONE_TEMPL_IS_EMPTY);
    } else {
        success(SUC_ZONE_TEMPL_ADD);
        $records = get_records_from_domain_id($zone_id);
        add_zone_templ_save_as($_POST['templ_name'], $_POST['templ_descr'], $_SESSION['userid'], $records, get_zone_name_from_id($zone_id));
    }
}

/*
  Check permissions
 */
if (verify_permission('zone_content_view_others')) {
    $perm_view = "all";
} elseif (verify_permission('zone_content_view_own')) {
    $perm_view = "own";
} else {
    $perm_view = "none";
}

if (verify_permission('zone_content_edit_others')) {
    $perm_content_edit = "all";
} elseif (verify_permission('zone_content_edit_own')) {
    $perm_content_edit = "own";
} else {
    $perm_content_edit = "none";
}

if (verify_permission('zone_meta_edit_others')) {
    $perm_meta_edit = "all";
} elseif (verify_permission('zone_meta_edit_own')) {
    $perm_meta_edit = "own";
} else {
    $perm_meta_edit = "none";
}

verify_permission('zone_master_add') ? $perm_zone_master_add = "1" : $perm_zone_master_add = "0";
verify_permission('zone_slave_add') ? $perm_zone_slave_add = "1" : $perm_zone_slave_add = "0";

$user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);
if ($perm_meta_edit == "all" || ( $perm_meta_edit == "own" && $user_is_zone_owner == "1")) {
    $meta_edit = "1";
} else {
    $meta_edit = "0";
}

(verify_permission('user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

if (isset($_POST['slave_master_change']) && is_numeric($_POST["domain"])) {
    change_zone_slave_master($_POST['domain'], $_POST['new_master']);
}
if (isset($_POST['type_change']) && in_array($_POST['newtype'], $server_types)) {
    change_zone_type($_POST['newtype'], $zone_id);
}
if (isset($_POST["newowner"]) && is_numeric($_POST["domain"]) && is_numeric($_POST["newowner"])) {
    add_owner_to_zone($_POST["domain"], $_POST["newowner"]);
}
if (isset($_POST["delete_owner"]) && is_numeric($_POST["delete_owner"])) {
    delete_owner_from_zone($zone_id, $_POST["delete_owner"]);
}
if (isset($_POST["template_change"])) {
    if (!isset($_POST['zone_template']) || "none" == $_POST['zone_template']) {
        $new_zone_template = 0;
    } else {
        $new_zone_template = $_POST['zone_template'];
    }
    if ($_POST['current_zone_template'] != $new_zone_template) {
        update_zone_records($zone_id, $new_zone_template);
    }
}

if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
    error(ERR_PERM_VIEW_ZONE);
    include_once("inc/footer.inc.php");
    exit();
}

if (zone_id_exists($zone_id) == "0") {
    error(ERR_ZONE_NOT_EXIST);
    include_once("inc/footer.inc.php");
    exit();
}

if (isset($_POST['sign_zone'])) {
    $zone_name = get_zone_name_from_id($zone_id);
    update_soa_serial($zone_id);
    dnssec_secure_zone($zone_name);
    dnssec_rectify_zone($zone_id);
}

if (isset($_POST['unsign_zone'])) {
    $zone_name = get_zone_name_from_id($zone_id);
    dnssec_unsecure_zone($zone_name);
    update_soa_serial($zone_id);
}

$domain_type = get_domain_type($zone_id);
$record_count = count_zone_records($zone_id);
$zone_templates = get_list_zone_templ($_SESSION['userid']);
$zone_template_id = get_zone_template($zone_id);

echo "   <h2>" . _('Edit zone') . " \"" . get_zone_name_from_id($zone_id) . "\"</h2>\n";

echo "   <div class=\"showmax\">\n";
show_pages($record_count, $iface_rowamount, $zone_id);
echo "   </div>\n";

$records = get_records_from_domain_id($zone_id, ROWSTART, $iface_rowamount, RECORD_SORT_BY);
if ($records == "-1") {
    echo " <p>" . _("This zone does not have any records. Weird.") . "</p>\n";
} else {
    echo "   <form method=\"post\" action=\"\">\n";
    echo "   <table>\n";
    echo "    <tr>\n";
    echo "     <th>&nbsp;</th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=id\">" . _('Id') . "</a></th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=name\">" . _('Name') . "</a></th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=type\">" . _('Type') . "</a></th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=content\">" . _('Content') . "</a></th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=prio\">" . _('Priority') . "</a></th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=ttl\">" . _('TTL') . "</a></th>\n";
    echo "    </tr>\n";
    foreach ($records as $r) {
        if ($r['type'] != "SOA") {
            echo "    <input type=\"hidden\" name=\"record[" . $r['id'] . "][rid]\" value=\"" . $r['id'] . "\">\n";
            echo "    <input type=\"hidden\" name=\"record[" . $r['id'] . "][zid]\" value=\"" . $zone_id . "\">\n";
        }
        echo "    <tr>\n";

        if ($domain_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0") {
            echo "     <td class=\"n\">&nbsp;</td>\n";
        } else {
            echo "     <td class=\"n\">\n";
            echo "      <a href=\"edit_record.php?id=" . $r['id'] . "&amp;domain=" . $zone_id . "\">
                                                <img src=\"images/edit.gif\" alt=\"[ " . _('Edit record') . " ]\"></a>\n";
            echo "      <a href=\"delete_record.php?id=" . $r['id'] . "&amp;domain=" . $zone_id . "\">
                                                <img src=\"images/delete.gif\" ALT=\"[ " . _('Delete record') . " ]\" BORDER=\"0\"></a>\n";
            echo "     </td>\n";
        }
        echo "     <td class=\"n\">{$r['id']}</td>\n";
        if ($r['type'] == "SOA") {
            echo "     <td class=\"n\">" . $r['name'] . "</td>\n";
            echo "     <td class=\"n\">" . $r['type'] . "</td>\n";
            echo "     <td class=\"n\">" . $r['content'] . "</td>\n";
            echo "     <td class=\"n\">&nbsp;</td>\n";
            echo "     <td class=\"n\">" . $r['ttl'] . "</td>\n";
        } else {
            echo "      <td class=\"u\"><input class=\"wide\" name=\"record[" . $r['id'] . "][name]\" value=\"" . htmlspecialchars($r['name']) . "\"></td>\n";
            echo "      <td class=\"u\">\n";
            echo "       <select name=\"record[" . $r['id'] . "][type]\">\n";
            $found_selected_type = false;
            foreach (get_record_types() as $type_available) {
                if ($type_available == $r['type']) {
                    $add = " SELECTED";
                    $found_selected_type = true;
                } else {
                    $add = "";
                }
                echo "         <option" . $add . " value=\"" . htmlspecialchars($type_available) . "\" >" . $type_available . "</option>\n";
            }
            if (!$found_selected_type)
                echo "         <option SELECTED value=\"" . htmlspecialchars($r['type']) . "\"><i>" . $r['type'] . "</i></option>\n";
            /*
              Sanitize content due to SPF record quoting in PowerDNS
             */
            if ($r['type'] == "SRV" || $r['type'] == "SPF" || $r['type'] == "TXT") {
                $clean_content = trim($r['content'], "\x22\x27");
            } else {
                $clean_content = $r['content'];
            }
            echo "       </select>\n";
            echo "      </td>\n";
            echo "      <td class=\"u\"><input class=\"wide\" name=\"record[" . $r['id'] . "][content]\" value=\"" . htmlspecialchars($clean_content) . "\"></td>\n";
            echo "      <td class=\"u\"><input size=\"4\" id=\"priority_field_" . $r['id'] . "\" name=\"record[" . $r['id'] . "][prio]\" value=\"" . htmlspecialchars($r['prio']) . "\"></td>\n";
            echo "      <td class=\"u\"><input size=\"4\" name=\"record[" . $r['id'] . "][ttl]\" value=\"" . htmlspecialchars($r['ttl']) . "\"></td>\n";
        }
        echo "     </tr>\n";
    }
    echo "    <tr>\n";
    echo "     <td colspan=\"6\">&nbsp;</td>\n";
    echo "    </tr>\n";
    echo "    <tr>\n";
    echo "     <td>&nbsp;</td><td colspan=\"5\">Comments:</td>\n";
    echo "    </tr>\n";
    echo "    <tr>\n";
    echo "     <td class=\"n\">\n";
    echo "      <a href=\"edit_comment.php?domain=" . $zone_id . "\">
                            <img src=\"images/edit.gif\" alt=\"[ " . _('Edit comment') . " ]\"></a>\n";
    echo "     </td>\n";
    echo "     <td colspan=\"4\"><textarea rows=\"5\" cols=\"80\" name=\"comment\">" . htmlspecialchars(get_zone_comment($zone_id)) . "</textarea></td>\n";
    echo "     <td>&nbsp;</td>\n";

    echo "     <tr>\n";
    echo "      <th colspan=\"6\"><br>Save as new template:</th>\n";
    echo "     </tr>\n";
    echo "     <tr>\n";
    echo "       <td colspan=\"2\">" . _('Template Name') . "</td>\n";
    echo "       <td><input class=\"wide\" type=\"text\" name=\"templ_name\" value=\"\"></td>\n";
    echo "      </tr>\n";
    echo "      <tr>\n";
    echo "       <td colspan=\"2\">" . _('Template Description') . "</td>\n";
    echo "       <td><input class=\"wide\" type=\"text\" name=\"templ_descr\" value=\"\"></td>\n";
    echo "      </tr>\n";
    echo "    </table>\n";
    echo "     <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
    echo "     <input type=\"reset\" class=\"button\" name=\"reset\" value=\"" . _('Reset changes') . "\">\n";
    echo "     <input type=\"submit\" class=\"button\" name=\"save_as\" value=\"" . _('Save as template') . "\">\n";

    if ($pdnssec_use) {
        $zone_name = get_zone_name_from_id($zone_id);

        if (dnssec_is_zone_secured($zone_name)) {
            echo "     <input type=\"button\" class=\"button\" name=\"dnssec\" onclick=\"location.href = 'dnssec.php?id=".$zone_id."';\" value=\"" . _('DNSSEC') . "\">\n";
            echo "     <input type=\"submit\" class=\"button\" name=\"unsign_zone\" value=\"" . _('Unsign this zone') . "\">\n";
        } else {
            echo "     <input type=\"submit\" class=\"button\" name=\"sign_zone\" value=\"" . _('Sign this zone') . "\">\n";
        }
    }

    echo "    </form>\n";
}

if ($perm_content_edit == "all" || $perm_content_edit == "own" && $user_is_zone_owner == "1") {
    if ($domain_type != "SLAVE") {
        $zone_name = get_zone_name_from_id($zone_id);
        echo "     <form method=\"post\" action=\"add_record.php?id=" . $zone_id . "\">\n";
        echo "      <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
        echo "      <table border=\"0\" cellspacing=\"4\">\n";
        echo "       <tr>\n";
        echo "        <td class=\"n\">" . _('Name') . "</td>\n";
        echo "        <td class=\"n\">&nbsp;</td>\n";
        echo "        <td class=\"n\">" . _('Type') . "</td>\n";
        echo "        <td class=\"n\">" . _('Content') . "</td>\n";
        echo "        <td class=\"n\">" . _('Priority') . "</td>\n";
        echo "        <td class=\"n\">" . _('TTL') . "</td>\n";
        echo "       </tr>\n";
        echo "       <tr>\n";
        echo "        <td class=\"n\"><input type=\"text\" name=\"name\" class=\"input\" value=\"\">." . $zone_name . "</td>\n";
        echo "        <td class=\"n\">IN</td>\n";
        echo "        <td class=\"n\">\n";
        echo "         <select name=\"type\">\n";
        $found_selected_type = !(isset($type) && $type);
        foreach (get_record_types() as $record_type) {
            if (isset($type) && $type) {
                if ($type == $record_type) {
                    $add = " SELECTED";
                    $found_selected_type = true;
                } else {
                    $add = "";
                }
            } else {
                if (preg_match('/i(p6|n-addr).arpa/i', $zone_name) && strtoupper($record_type) == 'PTR') {
                    $add = " SELECTED";
                    $rev = "";
                } else if ((strtoupper($record_type) == 'A') && $iface_add_reverse_record) {
                    $add = " SELECTED";
                    $rev = "<input type=\"checkbox\" name=\"reverse\"><span class=\"normaltext\">" . _('Add also reverse record') . "</span>\n";
                } else {
                    $add = "";
                }
            }
            echo "          <option" . $add . " value=\"" . htmlspecialchars($record_type) . "\">" . $record_type . "</option>\n";
        }
        if (!$found_selected_type)
            echo "         <option SELECTED value=\"" . htmlspecialchars($type) . "\"><i>" . htmlspecialchars($type) . "</i></option>\n";
        echo "         </select>\n";
        echo "        </td>\n";
        echo "        <td class=\"n\"><input type=\"text\" name=\"content\" class=\"input\" value=\"\"></td>\n";
        echo "        <td class=\"n\"><input type=\"text\" name=\"prio\" class=\"sinput\" value=\"\"></td>\n";
        echo "        <td class=\"n\"><input type=\"text\" name=\"ttl\" class=\"sinput\" value=\"\"></td>\n";
        echo "       </tr>\n";
        echo "      </table>\n";
        echo "      <input type=\"submit\" name=\"commit\" value=\"" . _('Add record') . "\" class=\"button\">\n";
        echo "      $rev";
        echo "     </form>\n";
    }
}

echo "   <div id=\"meta\">\n";
echo "    <table>\n";
echo "     <tr>\n";
echo "      <th colspan=\"2\">" . _('Owner of zone') . "</th>\n";
echo "     </tr>\n";

$owners = get_users_from_domain_id($zone_id);

if ($owners == "-1") {
    echo "      <tr><td>" . _('No owner set for this zone.') . "</td></tr>";
} else {
    if ($meta_edit) {
        foreach ($owners as $owner) {
            echo "       <tr>\n";
            echo "        <form method=\"post\" action=\"edit.php?id=" . $zone_id . "\">\n";
            echo "        <td>" . $owner["fullname"] . "</td>\n";
            echo "        <td>\n";
            echo "         <input type=\"hidden\" name=\"delete_owner\" value=\"" . $owner["id"] . "\">\n";
            echo "         <input type=\"submit\" class=\"sbutton\" name=\"co\" value=\"" . _('Delete') . "\">\n";
            echo "        </td>\n";
            echo "        </form>\n";
            echo "       </tr>\n";
        }
    } else {
        foreach ($owners as $owner) {
            echo "    <tr><td>" . $owner["fullname"] . "</td><td>&nbsp;</td></tr>";
        }
    }
}
if ($meta_edit) {
    echo "      <form method=\"post\" action=\"edit.php?id=" . $zone_id . "\">\n";
    echo "       <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
    echo "       <tr>\n";
    echo "        <td>\n";
    echo "         <select name=\"newowner\">\n";
    /*
      Show list of users to add as owners of this domain, only if we have permission to do so.
     */
    $users = show_users();
    foreach ($users as $user) {
        $add = '';
        if ($user["id"] == $_SESSION["userid"]) {
            echo "          <option" . $add . " value=\"" . $user["id"] . "\">" . $user["fullname"] . "</option>\n";
        } elseif ($perm_view_others == "1") {
            echo "          <option  value=\"" . $user["id"] . "\">" . $user["fullname"] . "</option>\n";
        }
    }
    echo "         </select>\n";
    echo "        </td>\n";
    echo "        <td>\n";
    echo "         <input type=\"submit\" class=\"sbutton\" name=\"co\" value=\"" . _('Add') . "\">\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "      </form>\n";
}
echo "      <tr>\n";
echo "       <th colspan=\"2\">" . _('Type') . "</th>\n";
echo "      </tr>\n";

if ($meta_edit) {
    echo "      <form action=\"" . htmlentities($_SERVER['PHP_SELF'], ENT_QUOTES) . "?id=" . $zone_id . "\" method=\"post\">\n";
    echo "       <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
    echo "       <tr>\n";
    echo "        <td>\n";
    echo "         <select name=\"newtype\">\n";
    foreach ($server_types as $type) {
        $add = '';
        if ($type == $domain_type) {
            $add = " SELECTED";
        }

        if (($perm_zone_master_add == "0" && $type == "MASTER") || ($perm_zone_slave_add == "0" && $type == "SLAVE")) {
            continue;
        }
        echo "          <option" . $add . " value=\"" . $type . "\">" . strtolower($type) . "</option>\n";
    }
    echo "         </select>\n";
    echo "        </td>\n";
    echo "        <td>\n";
    echo "         <input type=\"submit\" class=\"sbutton\" name=\"type_change\" value=\"" . _('Change') . "\">\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "      </form>\n";
} else {
    echo "      <tr><td>" . strtolower($domain_type) . "</td><td>&nbsp;</td></tr>\n";
}

echo "      <tr>\n";
echo "       <th colspan=\"2\">" . _('Template') . "</th>\n";
echo "      </tr>\n";

if ($meta_edit) {
    echo "      <form action=\"" . htmlentities($_SERVER['PHP_SELF'], ENT_QUOTES) . "?id=" . $zone_id . "\" method=\"post\">\n";
    echo "       <input type=\"hidden\" name=\"current_zone_template\" value=\"" . $zone_template_id . "\">\n";
    echo "       <tr>\n";
    echo "        <td>\n";
    echo "         <select name=\"zone_template\">\n";
    echo "          <option value=\"none\">none</option>\n";
    foreach ($zone_templates as $zone_template) {
        $add = '';
        if ($zone_template['id'] == $zone_template_id) {
            $add = " SELECTED";
        }
        echo "          <option .  $add . value=\"" . $zone_template['id'] . "\">" . $zone_template['name'] . "</option>\n";
    }
    echo "         </select>\n";
    echo "        </td>\n";
    echo "        <td>\n";
    echo "         <input type=\"submit\" class=\"sbutton\" name=\"template_change\" value=\"" . _('Change') . "\">\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "      </form>\n";
} else {
    $zone_template_details = get_zone_templ_details($zone_template_id);
    echo "      <tr><td>" . (isset($zone_template_details) ? strtolower($zone_template_details['name']) : "none" ) . "</td><td>&nbsp;</td></tr>\n";
}

if ($domain_type == "SLAVE") {
    $slave_master = get_domain_slave_master($zone_id);
    echo "      <tr>\n";
    echo "       <th colspan=\"2\">" . _('IP address of master NS') . "</th>\n";
    echo "      </tr>\n";

    if ($meta_edit) {
        echo "      <form action=\"" . htmlentities($_SERVER['PHP_SELF'], ENT_QUOTES) . "?id=" . $zone_id . "\" method=\"post\">\n";
        echo "       <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
        echo "       <tr>\n";
        echo "        <td>\n";
        echo "         <input type=\"text\" name=\"new_master\" value=\"" . $slave_master . "\" class=\"input\">\n";
        echo "        </td>\n";
        echo "        <td>\n";
        echo "         <input type=\"submit\" class=\"sbutton\" name=\"slave_master_change\" value=\"" . _('Change') . "\">\n";
        echo "        </td>\n";
        echo "       </tr>\n";
        echo "      </form>\n";
    } else {
        echo "      <tr><td>" . $slave_master . "</td><td>&nbsp;</td></tr>\n";
    }
}
echo "     </table>\n";
echo "   </div>\n"; // eo div meta

include_once("inc/footer.inc.php");
