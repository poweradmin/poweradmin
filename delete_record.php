<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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

/**
 * Script that handles record deletions from zones
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

global $pdnssec_use;

$record_id = "-1";
if (isset($_GET['id']) && v_num($_GET['id'])) {
    $record_id = $_GET['id'];
}

$confirm = "-1";
if (isset($_GET['confirm']) && v_num($_GET['confirm'])) {
    $confirm = $_GET['confirm'];
}

if (verify_permission('zone_content_edit_others')) {
    $perm_content_edit = "all";
} elseif (verify_permission('zone_content_edit_own')) {
    $perm_content_edit = "own";
} else {
    $perm_content_edit = "none";
}

$zid = get_zone_id_from_record_id($_GET['id']);
if ($zid == NULL) {
    header("Location: list_zones.php");
    exit;
}
$user_is_zone_owner = verify_user_is_owner_zoneid($zid);

$zone_info = get_zone_info_from_id($zid);

if ($record_id == "-1") {
    error(ERR_INV_INPUT);
} else {
    if ($confirm == '1') {
        $record_info = get_record_from_id($record_id);
        if (delete_record($record_id)) {
            success("<a href=\"edit.php?id=" . $zid . "\">" . SUC_RECORD_DEL . "</a>");
            if (isset($record_info['prio'])) {
                log_info(sprintf('client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s priority:%s',
                     $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                     $record_info['type'], $record_info['name'], $record_info['content'], $record_info['ttl'], $record_info['prio'] ));
            } else {
                log_info(sprintf('client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s',
                     $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                     $record_info['type'], $record_info['name'], $record_info['content'], $record_info['ttl'] ));

            }

            delete_record_zone_templ($record_id);

            // update serial after record deletion
            update_soa_serial($zid);

            if ($pdnssec_use) {
                // do also rectify-zone
                if (dnssec_rectify_zone($zid)) {
                    success(SUC_EXEC_PDNSSEC_RECTIFY_ZONE);
                }
            }
        }
    } else {
        $zone_id = recid_to_domid($record_id);
        $zone_name = get_zone_name_from_id($zone_id);
        $user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);
        $record_info = get_record_from_id($record_id);

        echo "     <h2>" . _('Delete record in zone') . " \"<a href=\"edit.php?id=" . $zid . "\">" . $zone_name . "</a>\"</h2>\n";

        if ($zone_info['type'] == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0") {
            error(ERR_PERM_EDIT_RECORD);
        } else {
            echo "     <table>\n";
            echo "      <tr>\n";
            echo "       <th>Name</th>\n";
            echo "       <th>Type</th>\n";
            echo "       <th>Content</th>\n";
            if (isset($record_info['prio'])) {
                echo "       <th>Priority</th>\n";
            }
            echo "       <th>TTL</th>\n";
            echo "      </tr>\n";
            echo "      <tr>\n";
            echo "       <td>" . $record_info['name'] . "</td>\n";
            echo "       <td>" . $record_info['type'] . "</td>\n";
            echo "       <td>" . $record_info['content'] . "</td>\n";
            if (isset($record_info['prio'])) {
                echo "       <td>" . $record_info['prio'] . "</td>\n";
            }
            echo "       <td>" . $record_info['ttl'] . "</td>\n";
            echo "      </tr>\n";
            echo "     </table>\n";
            if (($record_info['type'] == 'NS' && $record_info['name'] == $zone_name) || $record_info['type'] == 'SOA') {
                echo "     <p>" . _('You are trying to delete a record that is needed for this zone to work.') . "</p>\n";
            }
            echo "     <p>" . _('Are you sure?') . "</p>\n";
            echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='delete_record.php?id=" . $record_id . "&amp;confirm=1'\" value=\"" . _('Yes') . "\">\n";
            echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='edit.php?id=" . $zid . "'\" value=\"" . _('No') . "\">\n";
        }
    }
}
include_once("inc/footer.inc.php");
