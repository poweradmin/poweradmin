<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
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

$zone_templ_id = "-1";
if (isset($_GET['id']) && v_num($_GET['id'])) {
	$zone_templ_id = $_GET['id'];
}

if ($zone_templ_id == "-1") {
	error(ERR_INV_INPUT);
	include_once("inc/footer.inc.php");
	exit;
}

/*
Check permissions
*/
$owner = get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);

if (isset($_POST['commit']) && $owner) {
	foreach ($_POST['record'] as $record) {
		edit_zone_templ_record($record);
	}
}

if (!(verify_permission('zone_master_add')) || !$owner) {
        error(ERR_PERM_EDIT_ZONE_TEMPL);
} else {
	if (zone_templ_id_exists($zone_templ_id) == "0") {
		error(ERR_ZONE_TEMPL_NOT_EXIST);
	} else  {	
		$record_count = count_zone_templ_records($zone_templ_id);
		$templ_details = get_zone_templ_details($zone_templ_id);
		echo "   <h2>" . _('Edit zone template') . " \"" . $templ_details['name'] . "\"</h2>\n";

		echo "   <div class=\"showmax\">\n";
		show_pages($record_count,$iface_rowamount,$zone_templ_id);
		echo "   </div>\n";

		$records = get_zone_templ_records($zone_templ_id,ROWSTART,$iface_rowamount,RECORD_SORT_BY);
		if ( $records == "-1" ) { 
			echo " <p>" .  _("This template zone does not have any records yet.") . "</p>\n";
		} else {
			echo "   <form method=\"post\">\n";
			echo "   <table>\n";
			echo "    <tr>\n";
			echo "     <th>&nbsp;</th>\n";
			echo "     <th><a href=\"edit_zone_templ.php?id=" . $zone_templ_id . "&record_sort_by=name\">" . _('Name') . "</a></th>\n";
			echo "     <th><a href=\"edit_zone_templ.php?id=" . $zone_templ_id . "&record_sort_by=type\">" . _('Type') . "</a></th>\n";
			echo "     <th><a href=\"edit_zone_templ.php?id=" . $zone_templ_id . "&record_sort_by=content\">" . _('Content') . "</a></th>\n";
			echo "     <th><a href=\"edit_zone_templ.php?id=" . $zone_templ_id . "&record_sort_by=prio\">" . _('Priority') . "</a></th>\n";
			echo "     <th><a href=\"edit_zone_templ.php?id=" . $zone_templ_id . "&record_sort_by=ttl\">" . _('TTL') . "</a></th>\n";
			echo "    </tr>\n";
			foreach ($records as $r) {
				echo "    <input type=\"hidden\" name=\"record[" . $r['id'] . "][rid]\" value=\"" . $r['id'] . "\">\n";
				echo "    <tr>\n";
				echo "     <td class=\"n\">\n";
				echo "      <a href=\"edit_zone_templ_record.php?id=" . $r['id'] . "&amp;zone_templ_id=" . $zone_templ_id . "\">
						<img src=\"images/edit.gif\" alt=\"[ ". _('Edit record') . " ]\"></a>\n";
				echo "      <a href=\"delete_zone_templ_record.php?id=" . $r['id'] . "&amp;zone_templ_id=" . $zone_templ_id . "\">
						<img src=\"images/delete.gif\" ALT=\"[ " . _('Delete record') . " ]\" BORDER=\"0\"></a>\n";
				echo "     </td>\n";
				echo "      <td class=\"u\"><input class=\"wide\" name=\"record[" . $r['id'] . "][name]\" value=\"" . $r['name'] . "\"></td>\n";
				echo "      <td class=\"u\">\n";
				echo "       <select name=\"record[" . $r['id'] . "][type]\">\n";
				foreach (get_record_types() as $type_available) {
					if ($type_available == $r['type']) {
						$add = " SELECTED";
					} else {
						$add = "";
					}
					echo "         <option" . $add . " value=\"" . $type_available . "\" >" . $type_available . "</option>\n";
				}
				/*
				Sanitize content due to SPF record quoting in PowerDNS
				*/
				if($r['type'] == "SRV" || $r['type'] == "SPF"){
					$clean_content = trim($r['content'], "\x22\x27");
				}else{
					$clean_content = $r['content'];
				}
				echo "       </select>\n";
				echo "      </td>\n";
				echo "      <td class=\"u\"><input class=\"wide\" name=\"record[" . $r['id'] . "][content]\" value='" . $clean_content . "'></td>\n";
				if ($r['type'] == "MX" || $r['type'] == "SRV") { 
					echo "      <td class=\"u\"><input name=\"record[" . $r['id'] . "][prio]\" value=\"" .  $r['prio'] . "\"></td>\n";
				} else {
					echo "      <td class=\"n\">&nbsp;</td>\n";
				}
				echo "      <td class=\"u\"><input name=\"record[" . $r['id'] . "][ttl]\" value=\"" . $r['ttl'] . "\"></td>\n";
				echo "     </tr>\n";
			}
			echo "     <tr>\n";
			echo "      <td colspan=\"6\"><br><b>Hint:</b></td>\n";
			echo "     </tr>\n";
			echo "     <tr>\n";
			echo "      <td colspan=\"6\">" . _('The following placeholders can be used in template records') . "</td>\n";
			echo "     </tr>\n";
			echo "     <tr>\n";
			echo "      <td colspan=\"6\"><br>&nbsp;&nbsp;&nbsp;&nbsp; * [ZONE] - " . _('substituted with current zone name') . "<br>";
			echo "&nbsp;&nbsp;&nbsp;&nbsp; * [SERIAL] - " . _('substituted with current date and 2 numbers') . " (YYYYMMDD + 00)</td>\n";
			echo "     </tr>\n";
			echo "    </table>\n";
			echo "     <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
			echo "     <input type=\"reset\" class=\"button\" name=\"reset\" value=\"" . _('Reset changes') . "\">\n"; 
			echo "    </form>";
		}
		echo "    <input type=\"button\" class=\"button\" OnClick=\"location.href='add_zone_templ_record.php?id=" . $zone_templ_id . "'\" value=\"" . _('Add record') . "\">&nbsp;&nbsp\n";
		echo "    <input type=\"button\" class=\"button\" OnClick=\"location.href='delete_zone_templ.php?id=" . $zone_templ_id . "'\" value=\"" . _('Delete zone template') . "\">\n";
	}
}

include_once("inc/footer.inc.php");
?>
