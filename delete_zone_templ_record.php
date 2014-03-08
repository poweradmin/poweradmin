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
 * Script that record deletion from zone template
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

$record_id = "-1";
if (isset($_GET['id']) && v_num($_GET['id'])) {
    $record_id = $_GET['id'];
}

$zone_templ_id = "-1";
if (isset($_GET['zone_templ_id']) && v_num($_GET['zone_templ_id'])) {
    $zone_templ_id = $_GET['zone_templ_id'];
}

$confirm = "-1";
if (isset($_GET['confirm']) && v_num($_GET['confirm'])) {
    $confirm = $_GET['confirm'];
}

if ($record_id == "-1" || $zone_templ_id == "-1") {
    error(ERR_INV_INPUT);
} else {
    $owner = get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);
    if ($confirm == '1' && $owner) {
        if (delete_zone_templ_record($record_id)) {
            success(SUC_RECORD_DEL);
        }
    } else {
        $templ_details = get_zone_templ_details($zone_templ_id);
        $record_info = get_zone_templ_record_from_id($record_id);

        echo "     <h2>" . _('Delete record in zone') . " \"" . $templ_details['name'] . "\"</h2>\n";

        if (!(verify_permission('zone_master_add')) || !$owner) {
            error(ERR_PERM_DEL_RECORD);
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
            echo "       <td>" . $record_info['prio'] . "</td>\n";
            echo "       <td>" . $record_info['ttl'] . "</td>\n";
            echo "      </tr>\n";
            echo "     </table>\n";
            echo "     <p>" . _('Are you sure?') . "</p>\n";
            echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='delete_zone_templ_record.php?id=" . $record_id . "&amp;zone_templ_id=" . $zone_templ_id . "&amp;confirm=1'\" value=\"" . _('Yes') . "\">\n";
            echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='index.php'\" value=\"" . _('No') . "\">\n";
        }
    }
}
include_once("inc/footer.inc.php");
