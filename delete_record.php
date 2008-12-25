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

$variables_required_get = array('rid');
$variables_required_post = array();

require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; }
elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; }
else { $perm_content_edit = "none" ; }

$zid = get_zone_id_from_record_id($get['rid']);
$user_is_zone_owner = verify_user_is_owner_zoneid($zid);

if ($get['commit'] == '1') {
	if ( delete_record($get['rid']) ) {
		success(SUC_RECORD_DEL);
	}
} else {
	$zone_name = get_zone_name_from_id($zid);
	$user_is_zone_owner = verify_user_is_owner_zoneid($zid);
	$record_info = get_record_from_id($get['rid']);

	echo "     <h2>" . _('Delete record') . " in zone \"" . $zone_name . "\"</h2>\n";

	if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
		error(ERR_PERM_EDIT_RECORD);
	} else {
		echo "     <table>\n";
		echo "      <tr>\n";
		echo "       <th>Name</th>\n";
		echo "       <th>Type</th>\n";
		echo "       <th>Content</th>\n";
		echo "       <th>Priority</th>\n";
		echo "       <th>TTL</th>\n";
		echo "      </tr>\n";
		echo "      <tr>\n";
		echo "       <td>" . $record_info['name'] . "</td>\n";
		echo "       <td>" . $record_info['type'] . "</td>\n";
		echo "       <td>" . $record_info['content'] . "</td>\n";
		echo "       <td>" . $record_info['priority'] . "</td>\n";
		echo "       <td>" . $record_info['ttl'] . "</td>\n";
		echo "      </tr>\n";
		echo "     </table>\n";
		if (($record_info['type'] == 'NS' && $record_info['name'] == $zone_name) || $record_info['type'] == 'SOA') {
			echo "     <p>" . _('You are trying to delete a record that is needed for this zone to work.') . "</p>\n";
		}
		echo "     <p>" . _('Are you sure?') . "</p>\n";
		echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='" . $_SERVER["REQUEST_URI"] . "&commit=1'\" value=\"" . _('Yes') . "\">\n";
		echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='index.php'\" value=\"" . _('No') . "\">\n";
	}
}
include_once("inc/footer.inc.php");
