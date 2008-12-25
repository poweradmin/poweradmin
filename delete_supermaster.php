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

$variables_required_get = array('ip_master');
$variables_required_post = array();

require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

(verify_permission('supermaster_edit')) ? $perm_sm_edit = "1" :  $perm_sm_edit = "0" ;
if ($perm_sm_edit == "0") {
	error(ERR_PERM_DEL_SM);
} else {
	$info = get_supermaster_info_from_ip($get['ip_master']);

	echo "     <h2>" . _('Delete supermaster') . " \"" . $get['ip_master'] . "\"</h2>\n";

	if ($_GET["confirm"] == '1') {
		if (delete_supermaster($get['ip_master'])) {
			success(SUC_ZONE_DEL);
		}
	} else {
		echo "     <p>\n";
		echo "      " . _('Hostname in NS record') . ": " . $info['ns_name'] . "<br>\n";
		echo "      " . _('Account') . ": " . $info['account'] . "\n";
		echo "     </p>\n";
		echo "     <p>" . _('Are you sure?') . "</p>\n";
		echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='" . $_SERVER['REQUEST_URI'] . "&confirm=1'\" value=\"" . _('Yes') . "\">\n"; 
		echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='index.php'\" value=\"" . _('No') . "\">\n";
	}
}

include_once("inc/footer.inc.php");
