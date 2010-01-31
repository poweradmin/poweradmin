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

if (verify_permission('zone_content_edit_others')) { $perm_edit = "all" ; }
elseif (verify_permission('zone_content_edit_own')) { $perm_edit = "own" ;}
else { $perm_edit = "none" ; }

$zone_id = "-1";
if (isset($_GET['id']) && v_num($_GET['id'])) {
	$zone_id = $_GET['id'];
}

$confirm = "-1";
if (isset($_GET['confirm']) && v_num($_GET['confirm'])) {
	$confirm = $_GET['confirm'];
}

$zone_info = get_zone_info_from_id($zone_id);
$zone_owners = get_fullnames_owners_from_domainid($zone_id);
$user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);

if ($zone_id == "-1"){
	error(ERR_INV_INPUT);
	include_once("inc/footer.inc.php");
	exit;
}

echo "     <h2>" . _('Delete zone') . " \"" . $zone_info['name']. "\"</h2>\n";

if ($confirm == '1') {
	if ( delete_domain($zone_id) ) {
		success(SUC_ZONE_DEL);
	}
} else {
	if ( $perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1") ) {	
		echo "      " . _('Owner') . ": " . $zone_owners . "<br>\n";
		echo "      " . _('Type') . ": " . $zone_info['type'] . "\n";
		if ( $zone_info['type'] == "SLAVE" ) {
			$slave_master = get_domain_slave_master($zone_id);
			if(supermaster_exists($slave_master)) {
				echo "        <p>         \n";
				printf (_('You are about to delete a slave zone of which the master nameserver, %s, is a supermaster. Deleting the zone now, will result in temporary removal only. Whenever the supermaster sends a notification for this zone, it will be added again!'), $slave_master);
				echo "        </p>\n";
			}
		}
		echo "     <p>" . _('Are you sure?') . "</p>\n";
		echo "     <br><br>\n";
		echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='" . $_SERVER["REQUEST_URI"] . "&amp;confirm=1'\" value=\"" . _('Yes') . "\">\n";
		echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='index.php'\" value=\"" . _('No') . "\">\n";
	} else {
		error(ERR_PERM_DEL_ZONE);
	}
}

include_once("inc/footer.inc.php");

?>
