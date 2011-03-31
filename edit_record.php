<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2011  Poweradmin Development Team <http://www.poweradmin.org/credits>
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

require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

if (verify_permission('zone_content_view_others')) { $perm_view = "all" ; }
elseif (verify_permission('zone_content_view_own')) { $perm_view = "own" ; }
else { $perm_view = "none" ; }

if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; }
elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; }
else { $perm_content_edit = "none" ; }

if (verify_permission('zone_meta_edit_others')) { $perm_meta_edit = "all" ; }
elseif (verify_permission('zone_meta_edit_own')) { $perm_meta_edit = "own" ; }
else { $perm_meta_edit = "none" ; }

$zid = get_zone_id_from_record_id($_GET['id']);

$user_is_zone_owner = verify_user_is_owner_zoneid($zid);
$zone_type = get_domain_type($zid);
$zone_name = get_zone_name_from_id($zid);

if (isset($_POST["commit"])) {
	if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
		error(ERR_PERM_EDIT_RECORD);
	} else {
		$ret_val = edit_record($_POST);
		if ( $ret_val == "1" ) {
			update_soa_serial($zid);
			success(SUC_RECORD_UPD);
		} else {
			echo "     <div class=\"error\">" . $ret_val . "</div>\n";  
		}
	}
}

echo "    <h2>" . _('Edit record in zone') . " \"<a href=\"edit.php?id=".$zid."\">" .  $zone_name . "</a>\"</h2>\n";

if ( $perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0" ) {
	error(ERR_PERM_VIEW_RECORD);
} else {
	$record = get_record_from_id($_GET["id"]);
	echo "     <form method=\"post\" action=\"edit_record.php?domain=" . $zid . "&amp;id=" . $_GET["id"] . "\">\n";
	echo "      <table>\n";
	echo "       <tr>\n";
	echo "        <th>" . _('Name') . "</th>\n";
	echo "        <th>&nbsp;</th>\n";
	echo "        <th>" . _('Type') . "</th>\n";
	echo "        <th>" . _('Priority') . "</th>\n";
	echo "        <th>" . _('Content') . "</th>\n";
	echo "        <th>" . _('TTL') . "</th>\n";
	echo "       </tr>\n";

	if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
		echo "      <tr>\n";
		echo "       <td>" . $record["name"] . "</td>\n";
		echo "       <td>IN</td>\n";
		echo "       <td>" . $record["type"] . "</td>\n";
		echo "       <td>" . $record["content"] . "</td>\n";
		echo "       <td>" . $record["prio"] . "</td>\n";
		echo "       <td>" . $record["ttl"] . "</td>\n";
		echo "      </tr>\n";
	} else {
		echo "      <tr>\n";
		echo "       <td><input type=\"hidden\" name=\"rid\" value=\"" . $_GET["id"] . "\">\n";
		echo "       <input type=\"hidden\" name=\"zid\" value=\"" . $zid . "\">\n";
		echo "       <input type=\"text\" name=\"name\" value=\"" . trim(str_replace($zone_name, '', $record["name"]), '.') . "\" class=\"input\">." . $zone_name . "</td>\n";
		echo "       <td>IN</td>\n";
		echo "       <td>\n";
		echo "        <select name=\"type\">\n";
		foreach (get_record_types() as $type_available) {
			if ($type_available == $record["type"]) {
				$add = " SELECTED";
			} else {
				$add = "";
			}
			echo "         <option" . $add . " value=\"" . $type_available . "\" >" . $type_available . "</option>\n";
		}
		echo "        </select>\n";
		echo "       </td>\n";
		echo "       <td><input type=\"text\" name=\"prio\" value=\"" .  $record["prio"] . "\" class=\"sinput\"></td>\n";
		echo "       <td><input type=\"text\" name=\"content\" value=\"" .  $record["content"] . "\" class=\"input\"></td>\n";
		echo "       <td><input type=\"text\" name=\"ttl\" value=\"" . $record["ttl"] . "\" class=\"sinput\"></td>\n";
		echo "      </tr>\n";
	}
	echo "      </table>\n";
	echo "      <p>\n";
	echo "       <input type=\"submit\" name=\"commit\" value=\"" . _('Commit changes') . "\" class=\"button\">&nbsp;&nbsp;\n";
        echo "       <input type=\"reset\" name=\"reset\" value=\"" . _('Reset changes') . "\" class=\"button\">&nbsp;&nbsp;\n";
	echo "      </p>\n";
	echo "     </form>\n";
}


include_once("inc/footer.inc.php");
?>
