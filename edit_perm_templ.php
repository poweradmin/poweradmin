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

$id = "-1";
if ((isset($_GET['id'])) || (v_num($_GET['id']))) {
	$id = $_GET['id'] ;
}

if ($id == "-1") {
	error(ERR_INV_INPUT);
} elseif (!verify_permission(templ_perm_edit)) {
	error(ERR_PERM_EDIT_PERM_TEMPL);
} else {

	$id = $_GET['id'];
	$templ_details = get_permission_template_details($id);
	$perms_templ = get_permissions_by_template_id($id);
	$perms_avail = get_permissions_by_template_id();

	echo "    <h2>" . _('Edit permission template') . "</h2>\n"; // TODO i18n

	foreach ($templ_details as $templ) {
		echo "     <table>\n";
		echo "      <tr>\n";
		echo "       <th>" . _('Name') . "</th>\n"; // TODO i18n
		echo "       <td>" . $templ['name'] . "</td>\n";
		echo "      </tr>\n";
		echo "      <tr>\n";
		echo "       <th>" . _('Description') . "</th>\n"; // TODO i18n
		echo "       <td>" . $templ['descr'] . "</td>\n";
		echo "      </tr>\n";
		echo "     </table>\n";
	}


	echo "     <table>\n";
	echo "      <tr>\n";
	echo "       <th>TODO</th>\n";
	echo "       <th>Name</th>\n"; // TODO i18n
	echo "       <th>Description</th>\n"; // TODO i18n
	echo "      </tr>\n";

	foreach ($perms_avail as $perm) {
		echo "      <tr>\n";
		echo "       <td>&nbsp;</td>\n";
		echo "       <td>" . $perm['name'] . "</td>\n";
		echo "       <td>" . $perm['descr'] . "</td>\n";
		echo "      </tr>\n";
	}
	
	echo "     </table>\n";
}

include_once("inc/footer.inc.php");
?>
