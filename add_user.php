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

if (!verify_permission(user_add_new)) {
	error(ERR_PERM_ADD_USER);
} else {
	if($_POST["commit"]) {
		add_new_user($_POST);
		success(SUC_USER_ADD);
	}

	echo "     <h2>" . _('Add a  user') . "</h2>\n";
	echo "     <form method=\"post\">\n";
	echo "      <table>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Username') . "</td>\n"; 
	echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"username\" value=\"\"></td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Fullname') . "</td>\n"; 
	echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"fullname\" value=\"\"></td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Password') . "</td>\n";
	echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"password\"></td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Email') . "</td>\n"; 
	echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"email\" value=\"\"></td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Permission template') . "</td>\n"; 
	echo "        <td class=\"n\">\n";
	echo "         <select name=\"perm_templ\">\n";
	foreach (list_permission_templates() as $template) {
		echo "          <option value=\"" . $template['id'] . "\">" . $template['name'] . "</option>\n";
	}
	echo "         </select>\n";
	echo "       </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Description') . "</td>\n"; 
	echo "        <td class=\"n\"><textarea rows=\"4\" cols=\"30\" class=\"inputarea\" name=\"descr\"></textarea></td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Enabled') . "</td>\n"; 
	echo "        <td class=\"n\"><input type=\"checkbox\" class=\"input\" name=\"active\" value=\"1\"></td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">&nbsp;</td>\n"; 
	echo "        <td class=\"n\"><input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\"></td>\n"; 
	echo "      </table>\n";
	echo "     </form>\n";
}

include_once("inc/footer.inc.php");

?>
