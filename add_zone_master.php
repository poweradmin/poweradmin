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

$variables_required_get = array();
$variables_required_post = array();

require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

$owner = (isset($post['owner'])) ? $post['owner'] : "-1" ;
$zone_type = (isset($post['zone_type'])) ? $post['zone_type'] : "NATIVE";

$zone_name = trim($post['zone_name']);
$ip_web = $post['ip_web'];
$ip_mail = $post['ip_mail'];
$empty = $post['empty'];

(verify_permission('zone_master_add')) ? $zone_master_add = "1" : $zone_master_add = "0" ;

if ($post['commit'] && $zone_master_add == "1" ) {

	// Boy. I will be happy when I have found the time to replace
	// this "template wanabee" code with something that is really 
	// worth to be called "templating". Whoever wrote this should 
	// be... should be... how can I say this politicaly correct?
	// 20080303/RZ

        if(!$empty) {
                $empty = 0;
                if(!eregi('in-addr.arpa', $zone_name) && (!is_valid_ipv4($ip_web) || !is_valid_ipv4($ip_mail)) ) {
                        error(_('IP address of web- or mailserver is invalid.')); 
			$error = "1";
                }
        }

        if (!$error) {
                if (!is_valid_hostname_fqdn($zone_name,0)) {
                        error(ERR_DOMAIN_INVALID); 
			$error = "1";
                } elseif (domain_exists($zone_name)) {
                        error(ERR_DOMAIN_EXISTS); 
			$error = "1";
                } else {
                        if (add_domain($zone_name, $owner, $ip_web, $ip_mail, $empty, $zone_type, '')) {
				success(SUC_ZONE_ADD);
				unset($zone_name, $owner, $ip_web, $ip_mail, $empty, $zone_type);
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

	$available_zone_types = array("NATIVE", "MASTER");
	$users = show_users();

	echo "     <form method=\"post\" action=\"add_zone_master.php\">\n";
	echo "      <table>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Zone name') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"text\" class=\"input\" name=\"domain\" value=\"" .  $zone_name . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('IP address of webserver') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"text\" class=\"input\" name=\"ip_web\" value=\"" . $ip_web . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('IP address of mailserver') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"text\" class=\"input\" name=\"ip_mail\" value=\"" . $ip_mail . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Owner') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <select name=\"owner\">\n";
        foreach ($users as $user) {
		echo "          <option value=\"" . $user['uid'] . "\">" . $user['fullname'] . "</option>\n";
        }
	echo "         </select>\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Type') . ":</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <select name=\"zone_type\">\n";
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
	echo "         <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Add zone') . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "      </table>\n";
	echo "     </form>\n";
} 

include_once("inc/footer.inc.php");
