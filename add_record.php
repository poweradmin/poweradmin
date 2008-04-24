<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
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

if (verify_permission('zone_content_view_others')) { $perm_view = "all" ; }
elseif (verify_permission('zone_content_view_own')) { $perm_view = "own" ; }
else { $perm_view = "none" ; }

if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; }
elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; }
else { $perm_content_edit = "none" ; }

if (verify_permission('zone_meta_edit_others')) { $perm_meta_edit = "all" ; }
elseif (verify_permission('zone_meta_edit_own')) { $perm_meta_edit = "own" ; }
else { $perm_meta_edit = "none" ; }

$zone_id = "-1";
if ((isset($_GET['id'])) && (v_num($_GET['id']))) {
	$zone_id = $_GET['id'];
}

$ttl = $dns_ttl;
if ((isset($_POST['ttl'])) && (v_num($_POST['ttl']))) {
	$ttl = $_POST['ttl'];
}

$prio = "10";
if ((isset($_GET['prio'])) && (v_num($_GET['prio']))) {
	$prio = $_GET['prio'];
}

$name = $_POST['name'];
$type = $_POST['type'];
$content = $_POST['content'];

if ($zone_id == "-1") {
	error(ERR_INV_INPUT);
	include_once("inc/footer.inc.php");
	exit;
}

$user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);
$zone_type = get_domain_type($zone_id);
$zone_name = get_zone_name_from_id($zone_id);

if ($_POST["commit"]) {
	if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
		error(ERR_PERM_ADD_RECORD);
	} else {
		if ( add_record($zone_id, $name, $type, $content, $ttl, $prio)) {
			success(_('The record was successfully added.'));
			unset($zone_id, $name, $type, $content, $ttl, $prio);
		}
	}
}

echo "    <h2>" . _('Add record to zone') . " " .  $zone_name . "</h2>\n";

if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
	error(ERR_PERM_ADD_RECORD); 
} else {
	echo "     <form method=\"post\">\n";
	echo "      <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
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
	echo "        <td class=\"n\"><input type=\"text\" name=\"name\" class=\"input\" value=\"" . $name . "\">." . $zone_name . "</td>\n";
	echo "        <td class=\"n\">IN</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <select name=\"type\">\n";
	foreach (get_record_types() as $record_type) {
		if ($type) {
			if ($type == $record_type) {
				$add = " SELECTED";
			} else {
				unset ($add);
			}
		} else {
			if (eregi('in-addr.arpa', $zone_name) && strtoupper($record_type) == 'PTR') {
				$add = " SELECTED";
			} elseif (strtoupper($record_type) == 'A') {
				$add = " SELECTED";
			} else {
				unset($add);
			}
		}
		echo "          <option" . $add . " value=\"" . $record_type . "\">" . $record_type . "</option>\n";
	}
	echo "         </select>\n";
	echo "        </td>\n";
	echo "        <td class=\"n\"><input type=\"text\" name=\"prio\" class=\"sinput\" value=\"" . $prio . "\"></td>\n";
	echo "        <td class=\"n\"><input type=\"text\" name=\"content\" class=\"input\" value=\"" . $content . "\"></td>\n";
	echo "        <td class=\"n\"><input type=\"text\" name=\"ttl\" class=\"sinput\" value=\"" . $ttl . "\"</td>\n";
	echo "       </tr>\n";
	echo "      </table>\n";
	echo "      <br>\n";
	echo "      <input type=\"submit\" name=\"commit\" value=\"" .  _('Add record') . "\" class=\"button\">\n";
	echo "     </form>\n";
}

include_once("inc/footer.inc.php"); 

?>
