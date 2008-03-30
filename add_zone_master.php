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

$owner = "-1";
if ((isset($_POST['owner'])) && (v_num($_POST['owner']))) {
        $owner = $_POST['owner'];
}

$dom_type = "NATIVE";
if (isset($_POST["dom_type"]) && (in_array($_POST['dom_type'], $server_types))) {
	$dom_type = $_POST["dom_type"];
}

$domain = trim($_POST["domain"]);
$webip = $_POST["webip"];
$mailip = $_POST["mailip"];
$empty = $_POST["empty"];

(verify_permission(zone_master_add)) ? $zone_master_add = "1" : $zone_master_add = "0" ;

if ($_POST['submit'] && $zone_master_add == "1" ) {

	// Boy. I will be happy when I have found the time to replace
	// this "template wanabee" code with something that is really 
	// worth to be called "templating". Whoever wrote this should 
	// be... should be... how can I say this politicaly correct?
	// 20080303/RZ

        if(!$empty) {
                $empty = 0;
                if(!eregi('in-addr.arpa', $domain) && (!is_valid_ip($webip) || !is_valid_ip($mailip)) ) {
                        error(_('IP address of web- or mailserver is invalid.')); 
			$error = "1";
                }
        }

        if (!$error) {
                if (!is_valid_domain($domain)) {
                        error(ERR_DOMAIN_INVALID); 
			$error = "1";
                } elseif (domain_exists($domain)) {
                        error(ERR_DOMAIN_EXISTS); 
			$error = "1";
                } else {
                        if (add_domain($domain, $owner, $webip, $mailip, $empty, $dom_type, '')) {
				success(SUC_ZONE_ADD);
				unset($domain, $owner, $webip, $mailip, $empty, $dom_type);
			} else {
				$error = "1";
			}
                }
        }
}

if ( $zone_master_add != "1" ) {
	error(ERR_PERM_ADD_ZONE_MASTER); 
} else {
	echo "     <h2>" . _('Add master zone') . "</h2>\n"; 

	$available_zone_types = array("MASTER", "NATIVE");
	$users = show_users();

	echo "     <form method=\"post\" action=\"add_zone_master.php\">\n";
	echo "      <table>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Zone name') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"text\" class=\"input\" name=\"domain\" value=\"" .  $domain . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('IP address of webserver') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"text\" class=\"input\" name=\"webip\" value=\"" . $webip . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('IP address of mailserver') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"text\" class=\"input\" name=\"mailip\" value=\"" . $mailip . "\">\n";
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
	echo "        <td class=\"n\">" . _('Zone type') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <select name=\"dom_type\">\n";
        foreach($available_zone_types as $type) {
		echo "          <option value=\"" . $type . "\">" . strtolower($type) . "</option>\n";
        }
	echo "         </select>\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Create zone without applying records-template') . "</td>\n";
	echo "        <td class=\"n\"><input type=\"checkbox\" name=\"empty\" value=\"1\"></td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">&nbsp;</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"submit\" class=\"button\" name=\"submit\" value=\"" . _('Add zone') . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "      </table>\n";
	echo "     </form>\n";
} 

include_once("inc/footer.inc.php");
