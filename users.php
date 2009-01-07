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
verify_permission('user_view_others') ? $perm_view_others = "1" : $perm_view_others = "0" ;
verify_permission('user_edit_own') ? $perm_edit_own = "1" : $perm_edit_own = "0" ;
verify_permission('user_edit_others') ? $perm_edit_others = "1" : $perm_edit_others = "0" ;
verify_permission('templ_perm_edit') ? $perm_templ_perm_edit = "1" : $perm_templ_perm_edit = "0" ;
verify_permission('is_ueberuser') ? $perm_is_godlike = "1" : $perm_is_godlike = "0" ; 

unset($commit_button);

if (isset($_POST['commit'])) {
	foreach ($_POST['user'] as $user) {
		update_user_details($user);
	}
}

$users = get_user_detail_list("");
echo "    <h2>" . _('User administration') . "</h2>\n";
echo "    <form method=\"post\">\n";
echo "     <table>\n";
echo "      <tr>\n";
echo "       <th>&nbsp;</th>\n";
echo "       <th>" . _('Username') . "</th>\n";
echo "       <th>" . _('Fullname') . "</th>\n";
echo "       <th>" . _('Description') . "</th>\n";
echo "       <th>" . _('Emailaddress') . "</th>\n";
echo "       <th>" . _('Template') . "</th>\n";
echo "       <th>" . _('Enabled') . "</th>\n";
echo "      </tr>\n";

foreach ($users as $user) {
	if ($user['active'] == "1" ) {
		$active = " checked";
	} else {
		$active = "";
	}
	if (($user['uid'] == $_SESSION["userid"] && $perm_edit_own == "1") || ($user['uid'] != $_SESSION["userid"] && $perm_edit_others == "1" )) {
		$commit_button = "1";

		echo "      <input type=\"hidden\" name=\"user[" . $user['uid'] . "][uid]\" value=\"" . $user['uid'] . "\">\n";
		echo "      <tr>\n";
		echo "       <td>\n";
		echo "        <a href=\"edit_user.php?id=" . $user['uid'] . "\"><img src=\"images/edit.gif\" alt=\"[ " . _('Edit user') . "\" ]></a>\n";
		echo "        <a href=\"delete_user.php?id=" . $user['uid'] . "\"><img src=\"images/delete.gif\" alt=\"[ " . _('Delete user') . "\" ]></a>\n";
		echo "       </td>\n";
		echo "       <td><input type=\"text\" name=\"user[" . $user['uid'] . "][username]\" value=\"" . $user['username'] . "\"></td>\n";
		echo "       <td><input type=\"text\" name=\"user[" . $user['uid'] . "][fullname]\" value=\"" . $user['fullname'] . "\"></td>\n";
		echo "       <td><input type=\"text\" name=\"user[" . $user['uid'] . "][descr]\" value=\"" . $user['descr'] . "\"></td>\n";
		echo "       <td><input type=\"text\" name=\"user[" . $user['uid'] . "][email]\" value=\"" . $user['email'] . "\"></td>\n";
		echo "       <td>\n";
		if ($perm_templ_perm_edit == "1") {
			echo "        <select name=\"user[" . $user['uid'] . "][templ_id]\">\n";
			foreach (list_permission_templates() as $template) {
				($template['id'] == $user['tpl_id']) ? $select = " SELECTED" : $select = "" ;
				echo "          <option value=\"" . $template['id'] . "\"" . $select . ">" . $template['name'] . "</option>\n";
			}
			echo "         </select>\n";
		} else {
			echo "         <input type=\"hidden\" name=\"user[" . $user['uid'] . "][templ_id]\" value=\"" . $user['tpl_id'] . "\">\n";
			echo "         " . $user['tpl_name'] . "\n";
		}
		echo "       </td>\n";
		echo "       <td><input type=\"checkbox\" name=\"user[" . $user['uid'] . "][active]\"" . $active . "></td>\n";
		echo "      </tr>\n";
	} else {
		echo "      <tr>\n";
		echo "       <td>&nbsp;</td>\n";
		echo "       <td>" . $user['username'] . "</td>\n";
		echo "       <td>" . $user['fullname'] . "</td>\n";
		echo "       <td>" . $user['descr'] . "</td>\n";
		echo "       <td>" . $user['email'] . "</td>\n";
		echo "       <td>" . $user['tpl_name'] . "</td>\n";
		if ($active == " checked") {
			echo "       <td>Yes</td>\n";
		} else {
			echo "       <td>No</td>\n";
		}
		echo "      </tr>\n";
	}
}

echo "     </table>\n";
if ($commit_button) {
	echo "     <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
}
echo "    </form>\n";

echo "    <ul>\n";
if ($perm_templ_perm_edit == "1") {
	echo "<li><a href=\"list_perm_templ.php\">" . _('Edit permission template') . "</a>.</li>\n";
}

if (verify_permission('user_add_new')) {
	echo "<li><a href=\"add_user.php\">" . _('Add user') . "</a>.</li>\n";
}
echo "    </ul>\n";


include_once("inc/footer.inc.php");
?>
