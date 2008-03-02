<?php

/*  PowerAdmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
 *
 *  Copyright 2007, 2008  Rejo Zenger <rejo@zenger.nl>
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

if (verify_permission(zone_content_view_others)) { $perm_view = "all" ; }
elseif (verify_permission(zone_content_view_own)) { $perm_view = "own" ; }
else { $perm_view = "none" ; }

if (verify_permission(zone_content_edit_others)) { $perm_content_edit = "all" ; }
elseif (verify_permission(zone_content_edit_own)) { $perm_content_edit = "own" ; }
else { $perm_content_edit = "none" ; }

if (verify_permission(zone_content_edit_others)) { $perm_content_edit = "all" ; }
elseif (verify_permission(zone_content_edit_own)) { $perm_content_edit = "own" ; }
else { $perm_content_edit = "none" ; }

if (verify_permission(zone_meta_edit_others)) { $perm_meta_edit = "all" ; }
elseif (verify_permission(zone_meta_edit_own)) { $perm_meta_edit = "own" ; }
else { $perm_meta_edit = "none" ; }

$user_is_zone_owner = verify_user_is_owner_zoneid($_GET["id"]);
$zone_type = get_domain_type($_GET["id"]);
$zone_name = get_domain_name_from_id($_GET["id"]);

if ($_POST["commit"]) {
	if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
		echo "     <p>" . _("You do not have the permission to add a record to this zone.") . "</p>\n"; // TODO i18n
	} else {
		$ret_val = add_record($_POST["domain"], $_POST["name"], $_POST["type"], $_POST["content"], $_POST["ttl"], $_POST["prio"]);
		if ( $ret_val == "1" ) {
			echo "     <div class=\"success\">" .  _('The record was succesfully added.') . "</div>\n";
		} else {
			echo "     <div class=\"error\">" . $ret_val . "</div>\n";  //TODO i18n
		}
	}
}

echo "    <h2>" . _('Add record in zone') . " " .  $zone_name . "</h2>\n";

if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
        echo "     <p>" . _("You do not have the permission to add a record to this zone.") . "</p>\n"; // TODO i18n
} else {
	echo "     <form method=\"post\">\n";
	echo "      <input type=\"hidden\" name=\"domain\" value=\"" . $_GET["id"] . "\">\n";
	echo "      <table border=\"0\" cellspacing=\"4\">\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Name') . "</td>\n";
	echo "        <td class=\"n\">&nbsp;</td>\n";
	echo "        <td class=\"n\">" . _('Type') . "</td>\n";
	echo "        <td class=\"n\">" . _('Priority') .  "</td>\n";
	echo "        <td class=\"n\">" . _('Content') . "</td>\n";
	echo "        <td class=\"n\">" . _('TTL') . "</td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\"><input type=\"text\" name=\"name\" class=\"input\">." . $zone_name . "</td>\n";
	echo "        <td class=\"n\">IN</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <select name=\"type\">\n";
	foreach (get_record_types() as $record_type) {
		if (eregi('in-addr.arpa', $zone_name) && strtoupper($record_type) == 'PTR') {
			$add = " SELECTED";
		} elseif (strtoupper($record_type) == 'A') {
			$add = " SELECTED";
		} else {
			unset($add);
		}
		echo "          <option" . $add . " value=\"" . $record_type . "\">" . $record_type . "</option>\n";
	}
	echo "         </select>\n";
	echo "        </td>\n";
	echo "        <td class=\"n\"><input type=\"text\" name=\"prio\" class=\"sinput\"></td>\n";
	echo "        <td class=\"n\"><input type=\"text\" name=\"content\" class=\"input\"></td>\n";
	echo "        <td class=\"n\"><input type=\"text\" name=\"ttl\" class=\"sinput\" value=\"" . $DEFAULT_TTL . "\"</td>\n";
	echo "       </tr>\n";
	echo "      </table>\n";
	echo "      <br>\n";
	echo "      <input type=\"submit\" name=\"commit\" value=\"" .  _('Add record') . "\" class=\"button\">\n";
	echo "     </form>\n";
}

include_once("inc/footer.inc.php"); 

?>
