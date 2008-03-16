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
verify_permission(user_view_others) ? $perm_view_others = "1" : $perm_view_others = "0" ;
verify_permission(user_edit_own) ? $perm_edit_own = "1" : $perm_edit_own = "0" ;
verify_permission(user_edit_others) ? $perm_edit_others = "1" : $perm_edit_others = "0" ;
verify_permission(templ_perm_edit) ? $perm_templ_perm_edit = "1" : $perm_templ_perm_edit = "0" ;
verify_permission(is_ueberuser) ? $perm_is_godlike = "1" : $perm_is_godlike = "0" ; 

$users = get_user_detail_list("");
echo "    <h2>" . _('User admin') . "</h2>\n";
echo "     <table>\n";
echo "      <tr>\n";
echo "       <th>&nbsp;</th>\n";
echo "       <th>" . _('Username') . "</th>\n";
echo "       <th>" . _('Fullname') . "</th>\n";
echo "       <th>" . _('Description') . "</th>\n";
echo "       <th>" . _('Emailaddress') . "</th>\n";
echo "       <th>" . _('Enabled') . "</th>\n";
echo "       <th>" . _('Aantal zones') . "</th>\n"; // TODO i18n
echo "       <th>" . _('Template') . "</th>\n";
echo "      </tr>\n";

foreach ($users as $user) {
	$zone_count = zone_count_for_uid($user['uid']);
	if ($user['active'] == "1" ) {
		$active = _('Yes');
	} else {
		$active = _('No');
	}
	echo "      <tr>\n";
	echo "       <td>\n";
	if (($user['uid'] == $_SESSION["userid"] && $perm_edit_own == "1") || ($user['uid'] != $_SESSION["userid"] && $perm_edit_others == "1" )) {
		echo "        <a href=\"edit_user.php?id=" . $user["uid"] . "\"><img src=\"images/edit.gif\" alt=\"[ " . _('Edit user') . "\" ]></a>\n";
		echo "        <a href=\"delete_user.php?id=" . $user["uid"] . "\"><img src=\"images/delete.gif\" alt=\"[ " . _('Delete user') . "\" ]></a>\n";
	} else {
		echo "        &nbsp;\n";
	}
	echo "       </td>\n";
	echo "       <td>" . $user['username'] . "</td>\n";
	echo "       <td>" . $user['fullname'] . "</td>\n";
	echo "       <td>" . $user['descr'] . "</td>\n";
	echo "       <td>" . $user['email'] . "</td>\n";
	echo "       <td>" . $active . "</td>\n";
	echo "       <td>" . $zone_count . "</td>\n";
	echo "       <td>" . $user['tpl_name'] . "</td>\n";
	echo "      </tr>\n";
}

echo "     </table>\n";

if ($perm_templ_perm_edit == "1") {
	echo "     <p>" . _('Edit') . " <a href=\"list_perm_templ.php\">" . _('permission templates') . "</a>.</p>\n";
}

include_once("inc/footer.inc.php");
?>
