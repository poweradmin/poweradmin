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

$variables_required_get = array('zid');
$variables_required_post = array();

require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

if (isset($post['commit'])) {
	foreach ($post['record'] as $record) {
		edit_record($record);
	}
}

if (verify_permission('zone_content_view_others')) { $perm_view = "all" ; } 
elseif (verify_permission('zone_content_view_own')) { $perm_view = "own" ; } 
else { $perm_view = "none" ; }

if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; } 
elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; } 
else { $perm_content_edit = "none" ; }

if (verify_permission('zone_meta_edit_others')) { $perm_meta_edit = "all" ; } 
elseif (verify_permission('zone_meta_edit_own')) { $perm_meta_edit = "own" ; } 
else { $perm_meta_edit = "none" ; }

$user_is_zone_owner = verify_user_is_owner_zoneid($get['zid']);
if ( $perm_meta_edit == "all" || ( $perm_meta_edit == "own" && $user_is_zone_owner == "1") ) {
	$meta_edit = "1";
}

if(isset($post['slave_master_change']) && is_numeric($post['domain'])) change_zone_slave_master($post['domain'], $post['new_master']);
if(isset($post['type_change']) && in_array($post['newtype'], $server_types)) change_zone_type($post['newtype'], $get['zid']);
if(isset($post['newowner']) && is_numeric($post['domain']) && is_numeric($post['newowner'])) add_owner_to_zone($post['domain'], $post['newowner']);
if(isset($post['delete_owner']) && is_numeric($post['delete_owner']) ) delete_owner_from_zone($get['zid'], $post['delete_owner']);

if ( $perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0" ) {
	error(ERR_PERM_VIEW_ZONE);
} else {

	if (zone_id_exists($get['zid']) == "0") {
		error(ERR_ZONE_NOT_EXIST);
	} else  {	
		$domain_type=get_domain_type($get['zid']);
		$record_count=count_zone_records($get['zid']);

		echo "   <h2>" . _('Edit zone') . " \"" . get_zone_name_from_id($get['zid']) . "\"</h2>\n";

		echo "   <div class=\"showmax\">\n";
		show_pages($record_count,$iface_rowamount,$get['zid']);
		echo "   </div>\n";

		$records = get_records_from_domain_id($get['zid'],ROWSTART,$iface_rowamount);
		if ( $records == "-1" ) { 
			echo " <p>" .  _("This zone does not have any records. Weird.") . "</p>\n";
		} else {
			echo "   <form method=\"post\">\n";
			echo "   <table>\n";
			echo "    <tr>\n";
			echo "     <th>&nbsp;</th>\n";
			echo "     <th>" . _('Name') . "</th>\n";
			echo "     <th>" . _('Type') . "</th>\n";
			echo "     <th>" . _('Content') . "</th>\n";
			echo "     <th>" . _('Priority') . "</th>\n";
			echo "     <th>" . _('TTL') . "</th>\n";
			echo "    </tr>\n";
			foreach ($records as $r) {
				if ($r['type'] != "SOA") {
					echo "    <input type=\"hidden\" name=\"record[" . $r['rid'] . "][rid]\" value=\"" . $r['rid'] . "\">\n";
					echo "    <input type=\"hidden\" name=\"record[" . $r['rid'] . "][zid]\" value=\"" . $r['zid'] . "\">\n";
				}
				echo "    <tr>\n";
				
				if ( $domain_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
					echo "     <td class=\"n\">&nbsp;</td>\n";
				} else {
					echo "     <td class=\"n\">\n";
					echo "      <a href=\"edit_record.php?rid=" . $r['rid'] . "&amp;zid=" . $get['zid'] . "\">
							<img src=\"images/edit.gif\" alt=\"[ ". _('Edit record') . " ]\"></a>\n";
					echo "      <a href=\"delete_record.php?rid=" . $r['rid'] . "&amp;zid=" . $get['zid'] . "\">
							<img src=\"images/delete.gif\" ALT=\"[ " . _('Delete record') . " ]\" BORDER=\"0\"></a>\n";
					echo "     </td>\n";
				}
				if ($r['type'] == "SOA") {
					echo "     <td class=\"n\">" . $r['name'] . "</td>\n";
					echo "     <td class=\"n\">" . $r['type'] . "</td>\n";
					echo "     <td class=\"n\">" . $r['content'] . "</td>\n";
					echo "     <td class=\"n\">&nbsp;</td>\n";
					echo "     <td class=\"n\">" . $r['ttl'] . "</td>\n";
				} else {
					echo "      <td class=\"u\"><input class=\"wide\" name=\"record[" . $r['rid'] . "][name]\" value=\"" . $r['name'] . "\"></td>\n";
					echo "      <td class=\"u\">\n";
					echo "       <select name=\"record[" . $r['rid'] . "][type]\">\n";
					foreach (get_record_types() as $type_available) {
						if ($type_available == $r['type']) {
							$add = " SELECTED";
						} else {
							$add = "";
						}
						echo "         <option" . $add . " value=\"" . $type_available . "\" >" . $type_available . "</option>\n";
					}
					echo "       </select>\n";
					echo "      </td>\n";
					echo "      <td class=\"u\"><input class=\"wide\" name=\"record[" . $r['rid'] . "][content]\" value=\"" . $r['content'] . "\"></td>\n";
					if ($r['type'] == "MX") { 
						echo "      <td class=\"u\"><input name=\"record[" . $r['rid'] . "][prio]\" value=\"" .  $r['prio'] . "\"></td>\n";
					} else {
						echo "      <td class=\"n\">&nbsp;</td>\n";
					}
					echo "      <td class=\"u\"><input name=\"record[" . $r['rid'] . "][ttl]\" value=\"" . $r['ttl'] . "\"></td>\n";
				}
				echo "     </tr>\n";
			}
			echo "    </table>\n";
			echo "     <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
			echo "    </form>";
		}
		
		if ( $perm_content_edit == "all" || $perm_content_edit == "own" && $user_is_zone_owner == "1" ) {
			if ( $domain_type != "SLAVE") {
				echo "    <input type=\"button\" class=\"button\" OnClick=\"location.href='add_record.php?zid=" . $get['zid'] . "'\" value=\"" . _('Add record') . "\">&nbsp;&nbsp\n";
			}
			echo "    <input type=\"button\" class=\"button\" OnClick=\"location.href='delete_domain.php?zid=" . $get['zid'] . "'\" value=\"" . _('Delete zone') . "\">\n";
		}

		echo "   <div id=\"meta\">\n";
		echo "    <table>\n";
		echo "     <tr>\n";
		echo "      <th colspan=\"2\">" . _('Owner of zone') . "</th>\n";
		echo "     </tr>\n";

		$owners = get_users_from_domain_id($get['zid']);

		if ($owners == "-1") {
			echo "      <tr><td>" . _('No owner set for this zone.') . "</td></tr>";
		} else {
			if ($meta_edit) {
				foreach ($owners as $owner) {
					echo "      <form method=\"post\" action=\"edit.php?zid=" . $get['zid'] . "\">\n";
					echo "       <tr>\n";
					echo "        <td>" . $owner["fullname"] . "</td>\n";
					echo "        <td>\n";
					echo "         <input type=\"hidden\" name=\"delete_owner\" value=\"" . $owner["id"] . "\">\n";
					echo "         <input type=\"submit\" class=\"sbutton\" name=\"co\" value=\"" . _('Delete') . "\">\n";
					echo "        </td>\n";
					echo "       </tr>\n";
					echo "      </form>\n";
				}
			} else {
				foreach ($owners as $owner) {
					echo "    <tr><td>" . $owner["fullname"] . "</td><td>&nbsp;</td></tr>";
				}
			}

		}
		if ($meta_edit) {
			echo "      <form method=\"post\" action=\"edit.php?zid=" . $get['zid'] . "\">\n";
			echo "       <input type=\"hidden\" name=\"domain\" value=\"" . $get['zid'] . "\">\n";
			echo "       <tr>\n";
			echo "        <td>\n";
			echo "         <select name=\"newowner\">\n";
			$users = show_users();
			foreach ($users as $user) {
				$add = '';
				if ($user["id"] == $_SESSION["userid"]) {
					$add = " SELECTED";
				}
				echo "          <option" . $add . " value=\"" . $user["uid"] . "\">" . $user["fullname"] . "</option>\n";
			}
			echo "         </select>\n";
			echo "        </td>\n";
			echo "        <td>\n";
			echo "         <input type=\"submit\" class=\"sbutton\" name=\"co\" value=\"" . _('Add') . "\">\n";
			echo "        </td>\n";
			echo "       </tr>\n";
			echo "      </form>\n";
		}
		echo "      <tr>\n";
		echo "       <th colspan=\"2\">" . _('Type') . "</th>\n";
		echo "      </tr>\n";

		if ($meta_edit) {
			echo "      <form action=\"" . $_SERVER['PHP_SELF'] . "?id=" . $get['zid'] . "\" method=\"post\">\n";
			echo "       <input type=\"hidden\" name=\"domain\" value=\"" . $get['zid'] . "\">\n";
			echo "       <tr>\n";
			echo "        <td>\n";
			echo "         <select name=\"newtype\">\n";
			foreach($server_types as $type) {
				$add = '';
				if ($type == $domain_type) {
					$add = " SELECTED";
				}
				echo "          <option" .  $add . " value=\"" . $type . "\">" .  strtolower($type) . "</option>\n";
			}
			echo "         </select>\n";
			echo "        </td>\n";
			echo "        <td>\n";
			echo "         <input type=\"submit\" class=\"sbutton\" name=\"type_change\" value=\"" . _('Change') . "\">\n";
			echo "        </td>\n";
			echo "       </tr>\n";
			echo "      </form>\n";
		} else {
			echo "      <tr><td>" . strtolower($domain_type) . "</td><td>&nbsp;</td></tr>\n";
		}

		if ($domain_type == "SLAVE" ) { 
			$slave_master=get_domain_slave_master($get['zid']);
			echo "      <tr>\n";
			echo "       <th colspan=\"2\">" . _('IP address of master NS') . "</th>\n";
			echo "      </tr>\n";

			if ($meta_edit) {
				echo "      <form action=\"" . $_SERVER['PHP_SELF'] . "?id=" . $get['zid'] . "\" method=\"post\">\n";
				echo "       <input type=\"hidden\" name=\"domain\" value=\"" . $get['zid'] . "\">\n";
				echo "       <tr>\n";
				echo "        <td>\n";
				echo "         <input type=\"text\" name=\"new_master\" value=\"" . $slave_master . "\" class=\"input\">\n";
				echo "        </td>\n";
				echo "        <td>\n";
				echo "         <input type=\"submit\" class=\"sbutton\" name=\"slave_master_change\" value=\"" . _('Change') . "\">\n";
				echo "        </td>\n";
				echo "       </tr>\n";
				echo "      </form>\n";
			} else {
				echo "      <tr><td>" . $slave_master . "</td><td>&nbsp;</td></tr>\n";
			}
		}
		echo "     </table>\n";
		echo "   </div>\n";	// eo div meta 
	}
}

include_once("inc/footer.inc.php");
?>
