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

$owner = "-1";
if ((isset($_POST['owner'])) && (v_num($_POST['owner']))) {
        $owner = $_POST['owner'];
}

$zone = trim($_POST['domain']);
$master = $_POST['slave_master'];
$type = "SLAVE";

(verify_permission('zone_slave_add')) ? $zone_slave_add = "1" : $zone_slave_add = "0" ;

if ($_POST['submit'] && $zone_slave_add == "1") {
	if (!is_valid_domain($zone)) {
		error(ERR_DNS_HOSTNAME);
	} elseif (domain_exists($zone)) {
		error(ERR_DOMAIN_EXISTS);
	} elseif (!is_valid_ip($master)) {
		error(ERR_DNS_IP);
	} else {
		if(add_domain($zone, $owner, $webip, $mailip, $empty, $type, $master)) {
			success(SUC_ZONE_ADD);
			unset($zone, $owner, $webip, $mailip, $empty, $type, $master);
		}
	}
}

if ( $zone_slave_add != "1" ) {
	error(ERR_PERM_ADD_ZONE_SLAVE);
} else {
	echo "     <h2>" . _('Add slave zone') . "</h2>\n"; 

	$users = show_users();
	echo "     <form method=\"post\" action=\"add_zone_slave.php\">\n";
	echo "      <table>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Zone name') . "</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"text\" class=\"input\" name=\"domain\" value=\"" . $zone . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('IP address of master NS') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"text\" class=\"input\" name=\"slave_master\" value=\"" . $master . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Owner') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <select name=\"owner\">\n";
	foreach ($users as $user) {
		echo "          <option value=\"" . $user['id'] . "\">" . $user['fullname'] . "</option>\n";
	}
	echo "         </select>\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">&nbsp;</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"submit\" class=\"button\" name=\"submit\" value=\"" .  _('Add zone') . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "      </table>\n";
	echo "     </form>\n";
}

include_once("inc/footer.inc.php");
?>
