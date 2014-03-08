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
 */

/**
 * Script that handles records editing in zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

$record_id = "-1";
if (isset($_GET['id']) && v_num($_GET['id'])) {
    $record_id = $_GET['id'];
}

$zone_templ_id = "-1";
if (isset($_GET['zone_templ_id']) && v_num($_GET['zone_templ_id'])) {
    $zone_templ_id = $_GET['zone_templ_id'];
}

$owner = get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);

if (isset($_POST["commit"])) {
    if (!(verify_permission('zone_master_add')) || !$owner) {
        error(ERR_PERM_EDIT_RECORD);
    } else {
        $ret_val = edit_zone_templ_record($_POST);
        if ($ret_val == "1") {
            success(SUC_RECORD_UPD);
        } else {
            echo "     <div class=\"error\">" . $ret_val . "</div>\n";
        }
    }
}

$templ_details = get_zone_templ_details($zone_templ_id);
echo "    <h2>" . _('Edit record in zone template') . " \"" . $templ_details['name'] . "\"</h2>\n";

if (!(verify_permission('zone_master_add')) || !$owner) {
    error(ERR_PERM_VIEW_RECORD);
} else {
    $record = get_zone_templ_record_from_id($record_id);
    echo "     <form method=\"post\" action=\"edit_zone_templ_record.php?zone_templ_id=" . $zone_templ_id . "&id=" . $record_id . "\">\n";
    echo "      <table>\n";
    echo "       <tr>\n";
    echo "        <th>" . _('Name') . "</td>\n";
    echo "        <th>&nbsp;</td>\n";
    echo "        <th>" . _('Type') . "</td>\n";
    echo "        <th>" . _('Content') . "</td>\n";
    echo "        <th>" . _('Priority') . "</td>\n";
    echo "        <th>" . _('TTL') . "</td>\n";
    echo "       </tr>\n";
    echo "      <input type=\"hidden\" name=\"rid\" value=\"" . $record_id . "\">\n";
    echo "      <input type=\"hidden\" name=\"zid\" value=\"" . $zone_templ_id . "\">\n";
    echo "      <tr>\n";
    echo "       <td><input type=\"text\" name=\"name\" value=\"" . htmlspecialchars($record["name"]) . "\" class=\"input\"></td>\n";
    echo "       <td>IN</td>\n";
    echo "       <td>\n";
    echo "        <select name=\"type\">\n";
    $found_selected_type = false;
    foreach (get_record_types() as $type_available) {
        if ($type_available == $record["type"]) {
            $add = " SELECTED";
            $found_selected_type = true;
        } else {
            $add = "";
        }
        echo "         <option" . $add . " value=\"" . $type_available . "\" >" . $type_available . "</option>\n";
    }
    if (!$found_selected_type)
        echo "         <option SELECTED value=\"" . htmlspecialchars($record['type']) . "\"><i>" . $record['type'] . "</i></option>\n";
    /*
      Sanitize content due to SPF record quoting in PowerDNS
     */
    if ($record['type'] == "SRV" || $record['type'] == "SPF" || $record['type'] == "TXT") {
        $clean_content = trim($record['content'], "\x22\x27");
    } else {
        $clean_content = $record['content'];
    }
    echo "        </select>\n";
    echo "       </td>\n";
    echo "       <td><input type=\"text\" name=\"content\" value=\"" . htmlspecialchars($clean_content) . "\" class=\"input\"></td>\n";
    echo "       <td><input type=\"text\" name=\"prio\" value=\"" . htmlspecialchars($record["prio"]) . "\" class=\"sinput\"></td>\n";
    echo "       <td><input type=\"text\" name=\"ttl\" value=\"" . htmlspecialchars($record["ttl"]) . "\" class=\"sinput\"></td>\n";
    echo "      </tr>\n";
    echo "      </table>\n";
    echo "      <p>\n";
    echo "       <input type=\"submit\" name=\"commit\" value=\"" . _('Commit changes') . "\" class=\"button\">&nbsp;&nbsp;\n";
    echo "       <input type=\"reset\" name=\"reset\" value=\"" . _('Reset changes') . "\" class=\"button\">&nbsp;&nbsp;\n";
    echo "      </p>\n";
    echo "     </form>\n";
}

include_once("inc/footer.inc.php");
