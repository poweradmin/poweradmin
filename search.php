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

require_once('inc/toolkit.inc.php');
include_once('inc/header.inc.php');

if (!(verify_permission('search'))) {
	error(ERR_PERM_SEARCH);
	include_once('inc/footer.inc.php');
	exit;
	
} else {
	echo "     <h2>" . _('Search zones and records') . "</h2>\n";
	$holy_grail = '';
	if (isset($_POST['submit'])) {

		if (verify_permission('zone_content_view_others')) { $perm_view = "all" ; }
		elseif (verify_permission('zone_content_view_own')) { $perm_view = "own" ; }
		else { $perm_view = "none" ; }

		if (verify_permission('zone_content_edit_others')) { $perm_edit = "all" ; }
		elseif (verify_permission('zone_content_edit_own')) { $perm_edit = "own" ; }
		else { $perm_edit = "none" ; }
	
		$holy_grail = $_POST['query'];

		$result = search_zone_and_record($holy_grail,$perm_view);

		if (is_array($result['zones'])) {
			echo "     <h3>" . _('Zones found') . ":</h3>\n";
			echo "     <table>\n";
			echo "      <tr>\n";
			echo "       <th>&nbsp;</th>\n";
			echo "       <th>" . _('Name') . "</th>\n";
			echo "       <th>" . _('Type') . "</th>\n";
			echo "       <th>" . _('Master') . "</th>\n";
			echo "      </tr>\n";

			foreach ($result['zones'] as $zone) {
				echo "      <tr>\n";
				echo "          <td>\n";
				echo "           <a href=\"edit.php?id=" . $zone['zid'] . "\"><img src=\"images/edit.gif\" title=\"" . _('Edit zone') . " " . $zone['name'] . "\" alt=\"[ " . _('Edit zone') . " " . $zone['name'] . " ]\"></a>\n";
				if ( $perm_edit != "all" || $perm_edit != "none") {
					$user_is_zone_owner = verify_user_is_owner_zoneid($zone['zid']);
				}
				if ( $perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1") ) {
					echo "           <a href=\"delete_domain.php?id=" . $zone['zid'] . "\"><img src=\"images/delete.gif\" title=\"" . _('Delete zone') . " " . $zone['name'] . "\" alt=\"[ ". _('Delete zone') . " " . $zone['name'] . " ]\"></a>\n";
				}
				echo "          </td>\n";
				echo "       <td>" . $zone['name'] . "</td>\n";
				echo "       <td>" . $zone['type'] . "</td>\n";
				if ($zone['type'] == "SLAVE") {
					echo "       <td>" . $zone['master'] . "</td>\n";
				} else {
					echo "       <td>&nbsp;</td>\n";
				}
				echo "      </tr>\n";
			}
			echo "     </table>\n";
		}

		if (is_array($result['records'])) {
			echo "     <h3>" . _('Records found') . ":</h3>\n";
			echo "     <table>\n";
			echo "      <tr>\n";
			echo "       <th>&nbsp;</th>\n";
			echo "       <th>" . _('Name') . "</th>\n";
			echo "       <th>" . _('Type') . "</th>\n";
			echo "       <th>" . _('Priority') . "</th>\n";
			echo "       <th>" . _('Content') . "</th>\n";
			echo "       <th>" . _('TTL') . "</th>\n";
			echo "      </tr>\n";

			foreach ($result['records'] as $record) {

				echo "      <tr>\n";
				echo "          <td>\n";
				echo "           <a href=\"edit_record.php?id=" . $record['rid'] . "\"><img src=\"images/edit.gif\" title=\"" . _('Edit record') . " " . $record['name'] . "\" alt=\"[ " . _('Edit record') . " " . $record['name'] . " ]\"></a>\n";
				if ( $perm_edit != "all" || $perm_edit != "none") {
					$user_is_zone_owner = verify_user_is_owner_zoneid($record['zid']);
				}
				if ( $perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1") ) {
					echo "           <a href=\"delete_record.php?id=" . $record['rid'] . "\"><img src=\"images/delete.gif\" title=\"" . _('Delete record') . " " . $record['name'] . "\" alt=\"[ ". _('Delete record') . " " . $record['name'] . " ]\"></a>\n";
				}
				echo "          </td>\n";
				echo "       <td>" . $record['name'] . "</td>\n";
				echo "       <td>" . $record['type'] . "</td>\n";
				if ($record['type'] == "MX") {
					echo "       <td>" . $record['prio'] . "</td>\n";
				} else {
					echo "       <td>&nbsp;</td>\n";
				}
				echo "       <td>" . $record['content'] . "</td>\n";
				echo "       <td>" . $record['ttl'] . "</td>\n";
				echo "      </tr>\n";
			}
			echo "     </table>\n";
		}

	}

	echo "     <h3>" . _('Query') . ":</h3>\n";
	echo "      <form method=\"post\" action=\"" . $_SERVER['PHP_SELF'] . "\">\n";
	echo "       <table>\n";
	echo "        <tr>\n";
	echo "         <td>\n";
	echo "          <input type=\"text\" class=\"input\" name=\"query\" value=\"" . $holy_grail . "\">&nbsp;\n";
	echo "          <input type=\"submit\" class=\"button\" name=\"submit\" value=\"" . _('Search') . "\">\n";
	echo "         </td>\n";
	echo "        </tr>\n";
	echo "        <tr>\n";
	echo "         <td>\n";
	echo "          " . _('Enter a hostname or IP address. SQL LIKE syntax supported: an underscore (_) in pattern matches any single character, a percent sign (%) matches any string of zero or more characters.') . "\n";
	echo "         </td>\n";
	echo "        </tr>\n";
	echo "       </table>\n";
	echo "      </form>\n";

}
include_once('inc/footer.inc.php');
?>

