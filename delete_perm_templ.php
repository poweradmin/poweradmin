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

$perm_templ = "-1";
if (isset($_GET['id']) && (v_num($_GET['id']))) {
	 $perm_templ = $_GET['id'];
}

$confirm = "-1";
if ((isset($_GET['confirm'])) && v_num($_GET['confirm'])) {
        $confirm = $_GET['confirm'];
}

if ($perm_templ == "-1"){
	error(ERR_INV_INPUT);
} else {
	if (!(verify_permission('user_edit_templ_perm'))) {
		error(ERR_PERM_DEL_PERM_TEMPL);
	} else {
		$templ_details = get_permission_template_details($perm_templ);
		echo "     <h2>" . _('Delete permission template') . " \"" . $templ_details['name'] . "\"</h2>\n";

		if ($_GET["confirm"] == '1') {
			delete_perm_templ($perm_templ);
			success(SUC_PERM_TEMPL_DEL);
		} else {
			echo "     <p>" . _('Are you sure?') . "</p>\n";
			echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='" . $_SERVER['REQUEST_URI'] . "&confirm=1'\" value=\"" . _('Yes') . "\">\n"; 
		}
	}
}

include_once("inc/footer.inc.php");
