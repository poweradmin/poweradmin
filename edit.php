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
	foreach ($_POST['record'] as $record) {
		edit_record($record);
	}
}

if (verify_permission(zone_content_view_others)) { $perm_view = "all" ; } 
elseif (verify_permission(zone_content_view_own)) { $perm_view = "own" ; } 
else { $perm_view = "none" ; }

if (verify_permission(zone_content_edit_others)) { $perm_content_edit = "all" ; } 
elseif (verify_permission(zone_content_edit_own)) { $perm_content_edit = "own" ; } 
else { $perm_content_edit = "none" ; }

if (verify_permission(zone_meta_edit_others)) { $perm_meta_edit = "all" ; } 
elseif (verify_permission(zone_meta_edit_own)) { $perm_meta_edit = "own" ; } 
else { $perm_meta_edit = "none" ; }

$user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);
if ( $perm_meta_edit == "all" || ( $perm_meta_edit == "own" && $user_is_zone_owner == "1") ) {
	$meta_edit = "1";
}

if(isset($_POST['slave_master_change']) && is_numeric($_POST["domain"]) ) {
	change_zone_slave_master($_POST['domain'], $_POST['new_master']);
}
if(isset($_POST['type_change']) && in_array($_POST['newtype'], $server_types)) {
	change_zone_type($_POST['newtype'], $zone_id);
}
if(isset($_POST["newowner"]) && is_numeric($_POST["domain"]) && is_numeric($_POST["newowner"])) {
	add_owner_to_zone($_POST["domain"], $_POST["newowner"]);
}
if(isset($_POST["delete_owner"]) && is_numeric($_POST["delete_owner"]) ) {
	delete_owner_from_zone($zone_id, $_POST["delete_owner"]);
}

$domain_type=get_domain_type($zone_id);
$record_count=count_zone_records($zone_id);

echo "   <h2>" . _('Edit zone') . " \"" . get_domain_name_from_id($zone_id) . "\"</h2>\n";

if ( $perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0" ) {
	error(ERR_PERM_VIEW_ZONE);
} else {
	echo "   <div class=\"showmax\">\n";
	show_pages($record_count,ROWAMOUNT,$zone_id);
	echo "   </div>\n";

	$records = get_records_from_domain_id($zone_id,ROWSTART,ROWAMOUNT);
	if ( $records == "-1" ) { 
		echo " <p>" .  _("This zone does not have any records. Weird.") . "</p>\n";  // TODO i18n
	} else {
		echo "   <form method=\"post\">\n";
		echo "   <table>\n";
		echo "    <tr>\n";
		echo "     <th>&nbsp;</th>\n";
		echo "     <th>" . _('Name') . "</th>\n";
		echo "     <th>" . _('Type') . "</th>\n";
		echo "     <th>" . _('Content') . "</th>\n";
		echo "     <th>" . _('Priority') . "</th>\n";
		echo "     <th>" . _('TTL') . "</th>\n";
		echo "    </tr>\n";
		foreach ($records as $r) {
			echo "    <input type=\"hidden\" name=\"record[" . $r['id'] . "][rid]\" value=\"" . $r['id'] . "\">\n";
			echo "    <input type=\"hidden\" name=\"record[" . $r['id'] . "][zid]\" value=\"" . $zone_id . "\">\n";
			echo "    <tr>\n";
			if ( $domain_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
				echo "     <td class=\"n\">&nbsp;</td>\n";
			} else {
				echo "     <td class=\"n\">\n";
				echo "      <a href=\"edit_record.php?id=" . $r['id'] . "&amp;domain=" . $zone_id . "\">
						<img src=\"images/edit.gif\" alt=\"[ ". _('Edit record') . " ]\"></a>\n";
				echo "      <a href=\"delete_record.php?id=" . $r['id'] . "&amp;domain=" . $zone_id . "\">
						<img src=\"images/delete.gif\" ALT=\"[ " . _('Delete record') . " ]\" BORDER=\"0\"></a>\n";
				echo "     </td>\n";
			}
			echo "      <td class=\"u\"><input class=\"wide\" name=\"record[" . $r['id'] . "][name]\" value=\"" . $r['name'] . "\"></td>\n";
			echo "      <td class=\"u\">\n";
			echo "       <select name=\"record[" . $r['id'] . "][type]\">\n";
			foreach (get_record_types() as $type_available) {
				if ($type_available == $r["type"]) {
					$add = " SELECTED";
				} else {
					$add = "";
				}
				echo "         <option" . $add . " value=\"" . $type_available . "\" >" . $type_available . "</option>\n";
			}
			echo "       </select>\n";
			echo "      </td>\n";
			echo "      <td class=\"u\"><input class=\"wide\" name=\"record[" . $r['id'] . "][content]\" value=\"" . $r['content'] . "\"></td>\n";
			if ($r['type'] == "MX") { 
				echo "      <td class=\"u\"><input name=\"record[" . $r['id'] . "][prio]\" value=\"" .  $r['prio'] . "\"></td>\n";
			} else {
				echo "      <td class=\"n\">&nbsp;</td>\n";
			}
			echo "      <td class=\"u\"><input name=\"record[" . $r['id'] . "][ttl]\" value=\"" . $r['ttl'] . "\"></td>\n";
			echo "     </tr>\n";
		}
		echo "    </table>\n";
		echo "     <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
		echo "    </form>";
	}
	
	if ( $domain_type != "SLAVE" || $perm_content_edit != "none" || $perm_content_edit == "own" && $user_is_zone_owner == "1" ) {
		echo "    <input type=\"button\" class=\"button\" OnClick=\"location.href='add_record.php?id=" . $zone_id . "'\" value=\"" . _('Add record') . "\">&nbsp;&nbsp\n";
		echo "    <input type=\"button\" class=\"button\" OnClick=\"location.href='delete_domain.php?id=" . $zone_id . "'\" value=\"" . _('Delete zone') . "\">\n";
	}

	echo "   <div id=\"meta\">\n";
	echo "    <table>\n";
	echo "     <tr>\n";
	echo "      <th colspan=\"2\">" . _('Owner of zone') . "</th>\n";
	echo "     </tr>\n";

	$owners = get_users_from_domain_id($zone_id);

	if ($owners == "-1") {
		echo "      <tr><td>" . _('No owner set or this zone!') . "</td></tr>";
	} else {
		if ($meta_edit) {
			foreach ($owners as $owner) {
				echo "      <form method=\"post\" action=\"edit.php?id=" . $zone_id . "\">\n";
				echo "       <tr>\n";
				echo "        <td>" . $owner["fullname"] . "</td>\n";
				echo "        <td>\n";
				echo "         <input type=\"hidden\" name=\"delete_owner\" value=\"" . $owner["id"] . "\">\n";
				echo "         <input type=\"submit\" class=\"sbutton\" name=\"co\" value=\"" . _('Delete') . "\">\n";
				echo "        </td>\n";
				echo "       </tr>\n";
				echo "      </form>\n";
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
		$users = show_users();
		foreach ($users as $user) {
			unset($add);
			if ($user["id"] == $_SESSION["userid"])
			{
				$add = " SELECTED";
			}
			echo "          <option" . $add . " value=\"" . $user["id"] . "\">" . $user["fullname"] . "</option>\n";
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
	echo "       <th colspan=\"2\">" . _('Type of zone') . "</th>\n";
	echo "      </tr>\n";

	if ($meta_edit) {
		echo "      <form action=\"" . $_SERVER['PHP_SELF'] . "?id=" . $zone_id . "\" method=\"post\">\n";
		echo "       <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
		echo "       <tr>\n";
		echo "        <td>\n";
		echo "         <select name=\"newtype\">\n";
		foreach($server_types as $type) {
			unset($add);
			if ($type == $domain_type) {
				$add = " SELECTED";
			}
			echo "          <option" .  $add . " value=\"" . $type . "\">" .  strtolower($type) . "</option>\n";
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

	if ($domain_type == "SLAVE" ) { 
		$slave_master=get_domain_slave_master($zone_id);
		echo "      <tr>\n";
		echo "       <th colspan=\"2\">" . _('IP address of master NS') . "</th>\n";
		echo "      </tr>\n";

		if ($meta_edit) {
			echo "      <form action=\"" . $_SERVER['PHP_SELF'] . "?id=" . $zone_id . "\" method=\"post\">\n";
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
	echo "   </div>\n";	// eo div meta 
}
include_once("inc/footer.inc.php");
?>
