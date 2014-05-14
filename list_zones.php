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
 * Script that displays zone list
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

global $pdnssec_use;

if (verify_permission('zone_content_view_others')) {
    $perm_view = "all";
} elseif (verify_permission('zone_content_view_own')) {
    $perm_view = "own";
} else {
    $perm_view = "none";
}

if (verify_permission('zone_content_edit_others')) {
    $perm_edit = "all";
} elseif (verify_permission('zone_content_edit_own')) {
    $perm_edit = "own";
} else {
    $perm_edit = "none";
}

$count_zones_all = zone_count_ng("all");
$count_zones_all_letterstart = zone_count_ng($perm_view, LETTERSTART);
$count_zones_view = zone_count_ng($perm_view);
$count_zones_edit = zone_count_ng($perm_edit);

# OUCH: Temporary workaround for nasty sorting issue.
# The problem is that sorting order is saved as a session variable
# and it's used in two different screens - zone list and search results.
# Both have different queries for getting data, but same order field
# that causes failure.

$zone_sort_by = ZONE_SORT_BY;
if (!in_array(ZONE_SORT_BY, array('name', 'type', 'count_records'))) {
    $zone_sort_by = 'name';
}

echo "    <h2>" . _('List zones') . "</h2>\n";

if ($perm_view == "none") {
    echo "     <p>" . _('You do not have the permission to see any zones.') . "</p>\n";
} elseif (($count_zones_view > $iface_rowamount && $count_zones_all_letterstart == "0") || $count_zones_view == 0) {
    if ($count_zones_view > $iface_rowamount) {
        echo "<div class=\"showmax\">";
        show_letters(LETTERSTART);
        echo "</div>";
    }
    echo "     <p>" . _('There are no zones to show in this listing.') . "</p>\n";
} else {
    if (LETTERSTART != 'all') {
        echo "     <div class=\"showmax\">\n";
        show_pages($count_zones_all_letterstart, $iface_rowamount);
        echo "     </div>\n";
    }

    if ($count_zones_view > $iface_rowamount) {
        echo "<div class=\"showmax\">";
        show_letters(LETTERSTART);
        echo "</div>";
    }
    echo "     <form method=\"post\" action=\"delete_domains.php\">\n";
    echo "     <table>\n";
    echo "      <tr>\n";
    echo "       <th>&nbsp;</th>\n";
    echo "       <th>&nbsp;</th>\n";
    echo "       <th><a href=\"list_zones.php?zone_sort_by=name\">" . _('Name') . "</a></th>\n";
    echo "       <th><a href=\"list_zones.php?zone_sort_by=type\">" . _('Type') . "</a></th>\n";
    echo "       <th><a href=\"list_zones.php?zone_sort_by=count_records\">" . _('Records') . "</a></th>\n";
    echo "       <th>" . _('Owner') . "</th>\n";

    if ($iface_zonelist_serial) {
        echo "       <th>" . _('Serial') . "</th>\n";
    }

    if ($pdnssec_use) {
        echo "       <th>" . _('DNSSEC') . "</th>\n";
    }

    echo "      </tr>\n";

    if ($count_zones_view <= $iface_rowamount) {
        $zones = get_zones($perm_view, $_SESSION['userid'], "all", ROWSTART, $iface_rowamount, $zone_sort_by);
    } elseif (LETTERSTART == 'all') {
        $zones = get_zones($perm_view, $_SESSION['userid'], "all", ROWSTART, 'all', $zone_sort_by);
    } else {
        $zones = get_zones($perm_view, $_SESSION['userid'], LETTERSTART, ROWSTART, $iface_rowamount, $zone_sort_by);
        $count_zones_shown = ($zones == -1) ? 0 : count($zones);
    }
    foreach ($zones as $zone) {
        if ($zone['count_records'] == NULL) {
            $zone['count_records'] = 0;
        }

        $zone_owners = get_fullnames_owners_from_domainid($zone['id']);
        if ($iface_zonelist_serial)
            $serial = get_serial_by_zid($zone['id']);

        if ($perm_edit != "all" || $perm_edit != "none") {
            $user_is_zone_owner = verify_user_is_owner_zoneid($zone["id"]);
        }
        echo "         <tr>\n";
        echo "          <td class=\"checkbox\">\n";
        if ($count_zones_edit > 0 && ($perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1"))) {
            echo "       <input type=\"checkbox\" name=\"zone_id[]\" value=\"" . $zone['id'] . "\">";
        }
        echo "          </td>\n";
        echo "          <td class=\"actions\">\n";
        echo "           <a href=\"edit.php?name=" . $zone['name'] . "&id=" . $zone['id'] . "\"><img src=\"images/edit.gif\" title=\"" . _('View zone') . " " . $zone['name'] . "\" alt=\"[ " . _('View zone') . " " . $zone['name'] . " ]\"></a>\n";
        if ($perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1")) {
            echo "           <a href=\"delete_domain.php?name=" . $zone['name'] . "&id=" . $zone["id"] . "\"><img src=\"images/delete.gif\" title=\"" . _('Delete zone') . " " . $zone['name'] . "\" alt=\"[ " . _('Delete zone') . " " . $zone['name'] . " ]\"></a>\n";
        }
        echo "          </td>\n";
        echo "          <td class=\"name\">" . $zone["name"] . "</td>\n";
        echo "          <td class=\"type\">" . strtolower($zone["type"]) . "</td>\n";
        echo "          <td class=\"count\">" . $zone["count_records"] . "</td>\n";
        echo "          <td class=\"owner\">" . $zone_owners . "</td>\n";
        if ($iface_zonelist_serial) {
            if ($serial != "") {
                echo "          <td class=\"y\">" . $serial . "</td>\n";
            } else {
                echo "          <td class=\"n\">&nbsp;</td>\n";
            }
        }
        if ($pdnssec_use) {
            echo "          <td class=\"dnssec\"><input type=\"checkbox\" onclick=\"return false\" " . (dnssec_is_zone_secured($zone['name']) ? 'checked' : '') . "></td>\n";
        }
        echo "           </tr>\n";
    }
    echo "          </table>\n";
    echo "      <input type=\"submit\" name=\"commit\" value=\"" . _('Delete zone(s)') . "\" class=\"button\">\n";
    echo "     </form>\n";
}

include_once("inc/footer.inc.php");
