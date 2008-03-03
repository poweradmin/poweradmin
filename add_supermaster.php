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

if (verify_permission(supermaster_add)) { $supermasters_add = "1" ; }

if($_POST["submit"])
{
	$master_ip = $_POST["master_ip"];
	$ns_name = $_POST["ns_name"];
	$account = $_POST["account"];

	if (add_supermaster($master_ip, $ns_name, $account)) {
		echo "     <div class=\"success\">" .  _('Successfully added supermaster.') . "</div>\n";
	} else {
		$error = "1";
	}
}

echo "     <h2>" . _('Add supermaster') . "</h2>\n";

if ( $supermasters_add != "1" ) {
	echo "     <p>" . _("You do not have the permission to add a new supermaster.") . "</p>\n"; // TODO i18n
} else {
	echo "     <form method=\"post\" action=\"add_supermaster.php\">\n";
	echo "      <table>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('IP address of supermaster') . "</td>\n";
	echo "        <td class=\"n\">\n";
	if ($error) {
		echo "         <input type=\"text\" class=\"input\" name=\"master_ip\" value=\"" . $_POST["master_ip"] . "\">\n";
	} else {
		echo "         <input type=\"text\" class=\"input\" name=\"master_ip\" value=\"\">\n";
	}
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Hostname in NS record') . "</td>\n";
	echo "        <td class=\"n\">\n";
	if ($error) {
		echo "         <input type=\"text\" class=\"input\" name=\"ns_name\" value=\"" . $_POST["ns_name"] . "\">\n";
	} else {
		echo "         <input type=\"text\" class=\"input\" name=\"ns_name\" value=\"\">\n";
	}
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">" . _('Account') . "</td>\n";
	echo "        <td class=\"n\">\n";
	if ($error) {
		echo "         <input type=\"text\" class=\"input\" name=\"account\" value=\"" . $_POST["account"] . "\">\n";
	} else {
		echo "         <input type=\"text\" class=\"input\" name=\"account\" value=\"\">\n";
	}
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "       <tr>\n";
	echo "        <td class=\"n\">&nbsp;</td>\n";
	echo "        <td class=\"n\">\n";
	echo "         <input type=\"submit\" class=\"button\" name=\"submit\" value=\"" . _('Add supermaster') . "\">\n";
	echo "        </td>\n";
	echo "       </tr>\n";
	echo "      </table>\n";
	echo "     </form>\n";
}
include_once("inc/footer.inc.php");
?>
