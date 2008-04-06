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
else { $perm_view = "none" ;}

if (verify_permission('zone_content_edit_others')) { $perm_edit = "all" ; } 
elseif (verify_permission('zone_content_edit_own')) { $perm_edit = "own" ;} 
else { $perm_edit = "none" ; }

$count_zones_all = zone_count_ng("all");
$count_zones_all_letterstart = zone_count_ng($perm_view,LETTERSTART); 
$count_zones_view = zone_count_ng($perm_view);
$count_zones_edit = zone_count_ng($perm_edit);

echo "    <h2>" . _('List zones') . "</h2>\n";

if ($perm_view == "none") { 
	echo "     <p>" . _('You do not have the permission to see any zones.') . "</p>\n";
} elseif ($count_zones_view > ROWAMOUNT && $count_zones_all_letterstart == "0") {
	echo "     <p>" . _('There are no zones to show in this listing.') . "</p>\n";
} else {
	echo "     <div class=\"showmax\">\n";
	show_pages($count_zones_all_letterstart,ROWAMOUNT);
	echo "     </div>\n";

	if ($count_zones_view > ROWAMOUNT) {
		echo "<div class=\"showmax\">";
		show_letters(LETTERSTART);
		echo "</div>";
	}
	echo "     <table>\n";
	echo "      <tr>\n";
	echo "       <th>&nbsp;</th>\n";
	echo "       <th>" . _('Name') . "</th>\n";
	echo "       <th>" . _('Type') . "</th>\n";
	echo "       <th>" . _('Records') . "</th>\n";
	echo "       <th>" . _('Owner') . "</th>\n";
	echo "      </tr>\n";
	echo "      <tr>\n";

	if ($count_zones_view <= ROWAMOUNT) {
		$zones = get_zones($perm_view,$_SESSION['userid'],"all",ROWSTART,ROWAMOUNT);
	} else {
		$zones = get_zones($perm_view,$_SESSION['userid'],LETTERSTART,ROWSTART,ROWAMOUNT);
		$count_zones_shown = ($zones == -1) ? 0 : count($zones);
	}
	foreach ($zones as $zone)
	{
		$zone_owners = get_fullnames_owners_from_domainid($zone["id"]);

		echo "         <tr>\n";
		echo "          <td>\n";
		echo "           <a href=\"edit.php?id=" . $zone['id'] . "\"><img src=\"images/edit.gif\" title=\"" . _('View zone') . " " . $zone['name'] . "\" alt=\"[ " . _('View zone') . " " . $zone['name'] . " ]\"></a>\n";
		if ( $perm_edit != "all" || $perm_edit != "none") {
			$user_is_zone_owner = verify_user_is_owner_zoneid($zone["id"]);
		}
		if ( $perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1") ) {
      			echo "           <a href=\"delete_domain.php?id=" . $zone["id"] . "\"><img src=\"images/delete.gif\" title=\"" . _('Delete zone') . " " . $zone['name'] . "\" alt=\"[ ". _('Delete zone') . " " . $zone['name'] . " ]\"></a>\n";
		}
		echo "          </td>\n";
		echo "          <td class=\"y\">" . $zone["name"] . "</td>\n";
		echo "          <td class=\"y\">" . strtolower($zone["type"]) . "</td>\n";
		echo "          <td class=\"y\">" . $zone["count_records"] . "</td>\n";
		echo "          <td class=\"y\">" . $zone_owners . "</td>\n";
	}
	echo "           </tr>\n";
	echo "          </table>\n";

}

include_once("inc/footer.inc.php");
?>
