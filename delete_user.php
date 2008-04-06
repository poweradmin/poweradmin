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

verify_permission('user_edit_own') ? $perm_edit_own = "1" : $perm_edit_own = "0" ;
verify_permission('user_edit_others') ? $perm_edit_others = "1" : $perm_edit_others = "0" ;

if (!(isset($_GET['id']) && v_num($_GET['id']))) {
	error(ERR_INV_INPUT);
	include_once("inc/footer.inc.php");
	exit;
} else {
	$uid = $_GET['id'];
}

if ($_POST['commit']) {
	if (delete_user($uid,$_POST['zone'])) {
		success(SUC_USER_DEL);	
	}
} else {

	if (($uid != $_SESSION['userid'] && !verify_permission('user_edit_others')) || ($uid == $_SESSION['userid'] && !verify_permission('user_edit_own'))) {
		error(ERR_PERM_DEL_USER);
		include_once("inc/footer.inc.php");
		exit;
	} else {
		$fullname = get_fullname_from_userid($uid);
		$zones = get_zones("own",$uid);

		echo "     <h2>" . _('Delete user') . " \"" . $fullname . "\"</h2>\n";
		echo "     <form method=\"post\">\n";
		echo "      <table>\n";

		if (count($zones) > 0) {

			$users = show_users();

			echo "       <tr>\n";
			echo "        <td colspan=\"5\">\n";

			echo "         " . _('You are about to delete a user. This user is owner for a number of zones. Please decide what to do with these zones.') . "\n";
			echo "        </td>\n";
			echo "       </tr>\n";

			echo "       <tr>\n";
			echo "        <th>" . _('Zone') . "</th>\n";
			echo "        <th>" . _('Delete') . "</th>\n";
			echo "        <th>" . _('Leave') . "</th>\n";
			echo "        <th>" . _('Add new owner') . "</th>\n";
			echo "        <th>" . _('Owner to be added') . "</th>\n";
			echo "       </tr>\n";

			foreach ($zones as $zone) {
				echo "       <input type=\"hidden\" name=\"zone[" . $zone['id'] . "][zid]\" value=\"" . $zone['id'] . "\">\n";
				echo "       <tr>\n";
				echo "        <td>" . $zone['name'] . "</td>\n";
				echo "        <td><input type=\"radio\" name=\"zone[" . $zone['id'] . "][target]\" value=\"delete\"></td>\n";
				echo "        <td><input type=\"radio\" name=\"zone[" . $zone['id'] . "][target]\" value=\"leave\" CHECKED></td>\n";
				echo "        <td><input type=\"radio\" name=\"zone[" . $zone['id'] . "][target]\" value=\"new_owner\"></td>\n";
				echo "        <td>\n";
				echo "         <select name=\"zone[" . $zone['id'] . "][newowner]\">\n";

				foreach ($users as $user) {
					echo "          <option value=\"" . $user["id"] . "\">" . $user["fullname"] . "</option>\n";
				}

				echo "         </select>\n";
				echo "        </td>\n";
				echo "       </tr>\n";

			}
		}
		echo "       <tr>\n";
		echo "        <td colspan=\"5\">\n";

		echo "         " . _('Really delete this user?') . "\n";
		echo "        </td>\n";
		echo "       </tr>\n";

		echo "      </table>\n";
		echo "     <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
		echo "     </form>\n";
	}
}
include_once("inc/footer.inc.php");
?>
