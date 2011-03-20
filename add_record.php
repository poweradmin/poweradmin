<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
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

/*
Get permissions
*/
if (verify_permission('zone_content_view_others')) { $perm_view = "all" ; }
elseif (verify_permission('zone_content_view_own')) { $perm_view = "own" ; }
else { $perm_view = "none" ; }

if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; }
elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; }
else { $perm_content_edit = "none" ; }

if (verify_permission('zone_meta_edit_others')) { $perm_meta_edit = "all" ; }
elseif (verify_permission('zone_meta_edit_own')) { $perm_meta_edit = "own" ; }
else { $perm_meta_edit = "none" ; }


/*
Check and make sure all post values have made it through
if not set them.
*/
$zone_id = "-1";
if ((isset($_GET['id'])) && (v_num($_GET['id']))) {
	$zone_id = $_GET['id'];
}

$ttl = $dns_ttl;
if ((isset($_POST['ttl'])) && (v_num($_POST['ttl']))) {
	$ttl = $_POST['ttl'];
}

$prio = "10";
if ((isset($_POST['prio'])) && (v_num($_POST['prio']))) {
	$prio = $_POST['prio'];
}

if (isset($_POST['name'])) {
	$name = $_POST['name'];
} else {
	$name = "";
}

if (isset($_POST['type'])) { 
	$type = $_POST['type'];
} else {
	$type = "";
}

if (isset($_POST['content'])) { 
	$content = $_POST['content'];
} else {
	$content = "";
}

if ($zone_id == "-1") {
	error(ERR_INV_INPUT);
	include_once("inc/footer.inc.php");
	exit;
}

/*
Check and see if the user is the zone owner
Check the sone type and get the zone name
*/
$user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);
$zone_type = get_domain_type($zone_id);
$zone_name = get_zone_name_from_id($zone_id);

/*
If the form as been submitted
process it!
*/
if (isset($_POST["commit"])) {
	if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
		error(ERR_PERM_ADD_RECORD);
	} else {
		if ( add_record($zone_id, $name, $type, $content, $ttl, $prio)) {
			success(" <a href=\"edit.php?id=".$zone_id."\"> " ._('The record was successfully added.')."</a>");
			$name = $type = $content = $ttl = $prio = "";
		}
	}
}

/*
Display form to add a record
*/
echo "    <h2>" . _('Add record to zone') . " <a href=\"edit.php?id=".$zone_id."\"> " .  $zone_name . "</a></h2>\n";

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
	echo "        <td class=\"n\">" . _('Content') . "</td>\n";
	echo "        <td class=\"n\">" . _('Priority') .  "</td>\n";
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
				$add = "";
			}
		} else {
			if (preg_match('/in-addr.arpa/i', $zone_name) && strtoupper($record_type) == 'PTR') {
				$add = " SELECTED";
			} elseif (strtoupper($record_type) == 'A') {
				$add = " SELECTED";
			} else {
				$add = "";
			}
		}
		echo "          <option" . $add . " value=\"" . $record_type . "\">" . $record_type . "</option>\n";
	}
	echo "         </select>\n";
	echo "        </td>\n";
	echo "        <td class=\"n\"><input type=\"text\" name=\"content\" class=\"input\" value=\"" . $content . "\"></td>\n";
	echo "        <td class=\"n\"><input type=\"text\" name=\"prio\" class=\"sinput\" value=\"" . $prio . "\"></td>\n";
	echo "        <td class=\"n\"><input type=\"text\" name=\"ttl\" class=\"sinput\" value=\"" . $ttl . "\"</td>\n";
	echo "       </tr>\n";
	echo "      </table>\n";
	echo "      <br>\n";
	echo "      <input type=\"submit\" name=\"commit\" value=\"" .  _('Add record') . "\" class=\"button\">\n";
	echo "     </form>\n";
}

include_once("inc/footer.inc.php"); 

?>
