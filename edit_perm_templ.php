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

if (!verify_permission('templ_perm_edit')) {
	error(ERR_PERM_EDIT_PERM_TEMPL);
} else {

	if (isset($post['commit'])) {
		update_perm_templ_details($post);	
	}

	$templ = get_permission_template_details($pid);
	$perms_templ = get_permissions_by_template_id($pid);
	$perms_avail = get_permissions_by_template_id($pid);

	echo "    <h2>" . _('Edit permission template') . "</h2>\n"; 
        echo "    <form method=\"post\">\n";
	echo "    <input type=\"hidden\" name=\"templ_id\" value=\"" . $pid . "\">\n";

	echo "     <table>\n";
	echo "      <tr>\n";
	echo "       <th>" . _('Name') . "</th>\n"; 
	echo "       <td><input class=\"wide\" type=\"text\" name=\"templ_name\" value=\"" . $templ['name'] . "\"></td>\n";
	echo "      </tr>\n";
	echo "      <tr>\n";
	echo "       <th>" . _('Description') . "</th>\n"; 
	echo "       <td><input class=\"wide\" type=\"text\" name=\"templ_descr\" value=\"" . $templ['descr'] . "\"></td>\n";
	echo "      </tr>\n";
	echo "     </table>\n";

	echo "     <table>\n";
	echo "      <tr>\n";
	echo "       <th>&nbsp;</th>\n";
	echo "       <th>" . _('Name') . "</th>\n"; 
	echo "       <th>" . _('Description') . "</th>\n"; 
	echo "      </tr>\n";

	foreach ($perms_avail as $perm_a) {

		echo "      <tr>\n";

		$has_perm = "";
		foreach ($perms_templ as $perm_t) {
			if (in_array( $perm_a['id'], $perm_t )) {
				$has_perm = "checked";
			}
		}

		echo "       <td><input type=\"checkbox\" name=\"perm_id[]\" value=\"" . $perm_a['id'] . "\" " . $has_perm . "></td>\n";
		echo "       <td>" . $perm_a['name'] . "</td>\n";
		echo "       <td>" . _($perm_a['descr']) . "</td>\n";
		echo "      </tr>\n";
	}
	echo "     </table>\n";
	echo "     <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
	echo "     </form>\n";
}

include_once("inc/footer.inc.php");
?>
