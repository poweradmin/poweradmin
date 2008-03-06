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

(is_valid_ip($_GET['master_ip']) || is_valid_ip6($_GET['master_ip'])) ? $master_ip = $_GET['master_ip'] : $master_ip = "-1" ;
v_num($_GET['confirm']) ? $confirm = $_GET['confirm'] : $confirm = "-1";

if ($master_ip == "-1"){
	error(ERR_INV_INPUT);
} else {
	(verify_permission(supermaster_edit)) ? $perm_sm_edit = "1" :  $perm_sm_edit = "0" ;
	if ($perm_sm_edit == "0") {
		error(ERR_PERM_DEL_SM);
	} else {
		$info = get_supermaster_info_from_ip($master_ip);

		echo "     <h2>" . _('Delete supermaster') . " \"" . $master_ip . "\"</h2>\n";

		if ($_GET["confirm"] == '0') {
			// TODO redirect not working?
			clean_page("index.php");
		} elseif ($_GET["confirm"] == '1') {
			if (delete_supermaster($master_ip)) {
				success(SUC_ZONE_DEL);
			}
		} else {
			echo "     <p>\n";
			echo "      " . _('Hostname in NS record') . ": " . $info['ns_name'] . "<br>\n";
			echo "      " . _('Account') . ": " . $info['account'] . "\n";
			echo "     </p>\n";
			echo "     <p>" . _('Are you sure?') . "</p>\n";
			echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='" . $_SERVER['REQUEST_URI'] . "&confirm=1'\" value=\"" . _('Yes') . "\">\n"; 
			echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='" . $_SERVER['REQUEST_URI'] . "&confirm=0'\" value=\"" . _('No') . "\">\n";
		}
	}
}

include_once("inc/footer.inc.php");
