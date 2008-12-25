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

$variables_required_get = array('uid');
$variables_required_post = array();

require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

verify_permission('user_edit_own') ? $perm_edit_own = "1" : $perm_edit_own = "0" ;
verify_permission('user_edit_others') ? $perm_edit_others = "1" : $perm_edit_others = "0" ;

if (($get['uid'] == $_SESSION["userid"] && $perm_edit_own == "1") || ($get['uid'] != $_SESSION["userid"] && $perm_edit_others == "1" )) {

	if(isset($post['commit'])) {

		// TODO What to do if pid and/active are not set.
		$variables_required_post = array('username','fullname','email','descr','password');
		if (!minimum_variable_set($variables_required_get, $get)) {
			include_once("inc/footer.inc.php");
			exit;
		} else {
			if($post['username'] != "" && $post['pid'] > "0" && $post['fullname']) {
				if(!isset($post['active'])) {
					$post['active'] = 0;
				}
				if(edit_user($get['uid'], $post['username'], $post['fullname'], $post['email'], $post['pid'], $post['descr'], $post['active'], $post['password'])) {
					success(SUC_USER_UPD);
				} 
			}
		}
	}

	$users = get_user_detail_list($get['uid'])	;

	foreach ($users as $user) {
		
		(($user['active']) == "1") ? $check = " CHECKED" : $check = "" ;

		echo "     <h2>" . _('Edit user') . " \"" . $user['fullname'] . "\"</h2>\n";
		echo "     <form method=\"post\">\n";
		echo "      <input type=\"hidden\" name=\"number\" value=\"" . $get['uid'] . "\">\n";
		echo "      <table>\n";
		echo "       <tr>\n";
		echo "        <td class=\"n\">" . _('Username') . "</td>\n"; 
		echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"username\" value=\"" . $user['username'] . "\"></td>\n";
		echo "       </tr>\n";
		echo "       <tr>\n";
		echo "        <td class=\"n\">" . _('Fullname') . "</td>\n"; 
		echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"fullname\" value=\"" . $user['fullname'] . "\"></td>\n";
		echo "       </tr>\n";
		echo "       <tr>\n";
		echo "        <td class=\"n\">" . _('Password') . "</td>\n";
		echo "        <td class=\"n\"><input type=\"password\" class=\"input\" name=\"password\"></td>\n";
		echo "       </tr>\n";
		echo "       <tr>\n";
		echo "        <td class=\"n\">" . _('Emailaddress') . "</td>\n"; 
		echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"email\" value=\"" . $user['email'] . "\"></td>\n";
		echo "       </tr>\n";
		if (verify_permission('user_edit_templ_perm')) {
			echo "       <tr>\n";
			echo "        <td class=\"n\">" . _('Permission template') . "</td>\n"; 
			echo "        <td class=\"n\">\n";
			echo "         <select name=\"pid\">\n";
			foreach (list_permission_templates() as $template) {
				($template['id'] == $user['tpl_id']) ? $select = " SELECTED" : $select = "" ;
				echo "          <option value=\"" . $template['id'] . "\"" . $select . ">" . $template['name'] . "</option>\n";
			}
			echo "         </select>\n";
			echo "       </td>\n";
		}
		echo "       </tr>\n";
		echo "       <tr>\n";
		echo "        <td class=\"n\">" . _('Description') . "</td>\n"; 
		echo "        <td class=\"n\"><textarea rows=\"4\" cols=\"30\" class=\"inputarea\" name=\"description\">" . $user['descr'] . "</textarea></td>\n";
		echo "       </tr>\n";
		echo "       <tr>\n";
		echo "        <td class=\"n\">" . _('Enabled') . "</td>\n"; 
		echo "        <td class=\"n\"><input type=\"checkbox\" class=\"input\" name=\"active\" value=\"1\"" . $check . "></td>\n";
		echo "       </tr>\n";
		echo "       <tr>\n";
	echo "        <td class=\"n\">&nbsp;</td>\n"; 
		echo "        <td class=\"n\"><input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\"></td>\n"; 
		echo "      </table>\n";
		echo "     </form>\n";

		echo "     <p>\n";
		printf(_('This user has been assigned the permission template "%s".'), $user['tpl_name']);
		if ($user['tpl_descr'] != "") { 
			echo " " . _('The description for this template is') . ": \"" . $user['tpl_descr'] . "\".";
		}
		echo " " . _('Based on this template, this user has the following permissions') . ":";
		echo "     </p>\n";
		echo "     <ul>\n";
		foreach (get_permissions_by_template_id($user['tpl_id']) as $item) {
			echo "      <li>" . _($item['descr']) . " (" . $item['name'] . ")</li>\n";
		}
		echo "     </ul>\n";
	}
} else {
	error(ERR_PERM_EDIT_USER);
}

include_once("inc/footer.inc.php");

?>
